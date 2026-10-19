<?php

namespace App\Services;

use App\Enums\StoneMovementType;
use App\Enums\StonePricingMode;
use App\Models\Stone;
use App\Models\StoneIntake;
use App\Models\StoneIntakeItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StoneIntakeService
 *
 * Saves a scan batch as one all-or-nothing transaction: intake header,
 * line items with snapshots, stock increments via StoneStockService,
 * movements, cost, and an audit row. A partial apply is impossible.
 *
 * Piece and carat lots are received in pieces. A per-gram lot is received
 * in grams, which is added to its weight in the ledger.
 */
class StoneIntakeService
{
    public function __construct(
        private CodeSequenceService $codeSequenceService,
        private StoneStockService $stoneStockService,
        private StonePricingService $pricingService,
        private AuditLogService $auditLogService,
        private StoneIntakeDraftService $draftService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return StoneIntake::query()
            ->with(['creator'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('intake_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('intake_date', '<=', $to))
            ->when($filters['performed_by'] ?? null, fn ($q, $id) => $q->where('performed_by', $id))
            ->orderByDesc('intake_date')
            ->orderByDesc('id')
            ->paginate(per_page())
            ->withQueryString();
    }

    /**
     * Persist the batch. $rows is a list of { uuid, quantity | weight_grams, scanned_count }.
     *
     * @param  array{intake_date: string, notes?: ?string, rows: list<array<string, mixed>>}  $payload
     */
    public function save(array $payload, User $actor): StoneIntake
    {
        $rows = $payload['rows'] ?? [];

        if ($rows === []) {
            throw ValidationException::withMessages([
                'rows' => __('Scan or add at least one stone before saving.'),
            ]);
        }

        return DB::transaction(function () use ($payload, $rows, $actor): StoneIntake {
            $resolved = $this->resolveRows($rows, (int) $actor->branch_id);

            $totalQty = 0;
            $totalCarat = 0.0;
            $totalGrams = 0.0;

            foreach ($resolved as $line) {
                $totalQty += $line['quantity'];
                $totalGrams += $line['grams'];
                // Only per-carat lots carry a weight per piece; the rest add nothing.
                $totalCarat += (float) ($line['stone']->carat ?? 0) * $line['quantity'];
            }

            $intake = StoneIntake::query()->create([
                'branch_id' => $actor->branch_id,
                'intake_number' => $this->codeSequenceService->nextIntakeNumber(),
                'intake_date' => $payload['intake_date'],
                'total_stone_types' => count($resolved),
                'total_quantity' => $totalQty,
                'total_carat' => round($totalCarat, 3),
                'total_weight_grams' => round($totalGrams, 3),
                'notes' => $payload['notes'] ?? null,
            ]);

            foreach ($resolved as $line) {
                /** @var Stone $stone */
                $stone = $line['stone'];

                StoneIntakeItem::query()->create([
                    'stone_intake_id' => $intake->id,
                    'stone_id' => $stone->id,
                    'quantity' => $line['quantity'],
                    'weight_grams' => $line['grams'] > 0 ? number_format($line['grams'], 3, '.', '') : null,
                    'carat_snapshot' => $stone->carat,
                    'name_snapshot' => $stone->name,
                    'scanned_count' => $line['scanned_count'],
                ]);

                $note = 'Intake '.$intake->intake_number;

                if ($line['grams'] > 0) {
                    $this->stoneStockService->applyWeight(
                        $stone,
                        StoneMovementType::INTAKE,
                        $line['grams'],
                        $actor,
                        $intake,
                        $note,
                    );
                } else {
                    $this->stoneStockService->apply(
                        $stone,
                        StoneMovementType::INTAKE,
                        $line['quantity'],
                        $actor,
                        $intake,
                        $note,
                    );
                }

                // What came in was bought at the lot's own rate, so its cost grows.
                $stone->fill($this->pricingService->costAfterReceiving($stone, $line['quantity'], $line['grams']));
                $stone->save();
            }

            $this->auditLogService->log('stone_intake.created', $intake, null, [
                'intake_number' => $intake->intake_number,
                'total_quantity' => $intake->total_quantity,
                'total_carat' => (string) $intake->total_carat,
                'total_weight_grams' => (string) $intake->total_weight_grams,
                'total_stone_types' => $intake->total_stone_types,
            ], $actor);

            $this->draftService->discard($actor);

            return $intake->load(['items.stone']);
        });
    }

    /**
     * Reload stones inside the transaction and reject inactive / foreign / missing.
     *
     * Each line must carry what its lot is counted in: pieces for a piece or
     * carat lot, grams for a per-gram lot.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{stone: Stone, quantity: int, grams: float, scanned_count: int}>
     */
    private function resolveRows(array $rows, int $branchId): array
    {
        $seen = [];
        $resolved = [];

        foreach ($rows as $index => $row) {
            $uuid = (string) ($row['uuid'] ?? '');

            if ($uuid === '') {
                throw ValidationException::withMessages([
                    "rows.{$index}.uuid" => __('Each row needs an active stone.'),
                ]);
            }

            if (isset($seen[$uuid])) {
                throw ValidationException::withMessages([
                    "rows.{$index}.uuid" => __('The same stone appears twice. Combine quantities on one row.'),
                ]);
            }
            $seen[$uuid] = true;

            $stone = Stone::query()->where('uuid', $uuid)->lockForUpdate()->first();

            if (! $stone || (int) $stone->branch_id !== $branchId || ! $stone->is_active) {
                throw ValidationException::withMessages([
                    "rows.{$index}.uuid" => __('A scanned stone is missing, inactive, or belongs to another branch. Remove it and scan again.'),
                ]);
            }

            $isGram = $stone->pricing_mode === StonePricingMode::PER_GRAM;
            $qty = (int) ($row['quantity'] ?? 0);
            $grams = round((float) ($row['weight_grams'] ?? 0), 3);

            if ($isGram && $grams <= 0) {
                throw ValidationException::withMessages([
                    "rows.{$index}.weight_grams" => __('Enter the grams received for :name.', ['name' => $stone->name]),
                ]);
            }

            if (! $isGram && $qty < 1) {
                throw ValidationException::withMessages([
                    "rows.{$index}.quantity" => __('Enter at least 1 piece for :name.', ['name' => $stone->name]),
                ]);
            }

            $resolved[] = [
                'stone' => $stone,
                // A lot is counted one way only; the other figure is ignored.
                'quantity' => $isGram ? 0 : $qty,
                'grams' => $isGram ? $grams : 0.0,
                'scanned_count' => max(0, (int) ($row['scanned_count'] ?? 0)),
            ];
        }

        return $resolved;
    }
}
