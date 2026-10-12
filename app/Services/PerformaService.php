<?php

namespace App\Services;

use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\MakingChargeType;
use App\Enums\PerformaStatus;
use App\Enums\StoneMovementType;
use App\Models\Creditor;
use App\Models\JewelleryItem;
use App\Models\Performa;
use App\Models\PerformaItem;
use App\Models\Stone;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PerformaService
 *
 * Issues and returns credit ledgers. Stock leaves on issue (jewellery on
 * memo, loose stones ISSUED_ON_CREDIT). Prices are never written here —
 * settlement invoices own the money.
 */
class PerformaService
{
    public function __construct(
        private CodeSequenceService $codeSequence,
        private SalesLookupService $lookupService,
        private JewelleryItemService $jewelleryItemService,
        private StoneStockService $stoneStockService,
        private AuditLogService $auditLog,
    ) {}

    /**
     * Branch-scoped list with outstanding qty summaries.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Performa::query()
            ->with(['creditor', 'creator', 'items'])
            ->withCount('items')
            ->orderByDesc('performa_date')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('performa_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('performa_date', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($term): void {
                $q->where('performa_number', 'like', "%{$term}%")
                    ->orWhereHas('creditor', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
            });
        }

        return $query->paginate(per_page())->withQueryString();
    }

    /**
     * Load a performa with lines and settlement invoices.
     */
    public function loadDetail(Performa $performa): Performa
    {
        return $performa->load([
            'creditor',
            'branch',
            'creator',
            'updater',
            'items.jewelleryItem.itemStones',
            'items.jewelleryItem.category',
            'items.jewelleryItem.metalType',
            'items.jewelleryItem.purity',
            'items.stone',
            'settlements.invoice',
            'settlements.creator',
        ]);
    }

    /**
     * Issue a performa: attach creditor, lock stock, write lines, deduct now.
     *
     * @param  array<string, mixed>  $data
     */
    public function issue(array $data, User $actor): Performa
    {
        if ((int) $actor->branch_id < 1) {
            abort(403);
        }

        $lines = $this->lookupService->hydrateLines($data['lines'] ?? [], $actor);

        return DB::transaction(function () use ($data, $lines, $actor): Performa {
            $this->assertLinesAvailable($lines, $actor);

            $creditorId = $this->resolveCreditorId($data, $actor);
            if ($creditorId === null) {
                throw ValidationException::withMessages([
                    'creditor_uuid' => 'Select a creditor. Register them under Creditors first.',
                ]);
            }

            $performa = Performa::query()->create([
                'branch_id' => $actor->branch_id,
                'performa_number' => $this->codeSequence->nextPerformaNumber(),
                'creditor_id' => $creditorId,
                'performa_date' => $data['performa_date'] ?? now()->toDateString(),
                'status' => PerformaStatus::OPEN,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $index => $line) {
                $this->writeLine($performa, $line, $index);
                $this->applyIssueStock($line, $performa, $actor);
            }

            $this->auditLog->log('performa.issued', $performa, null, [
                'performa_number' => $performa->performa_number,
                'creditor_id' => $creditorId,
                'lines' => count($lines),
            ], $actor);

            return $this->loadDetail($performa->fresh());
        });
    }

    /**
     * Return unpaid outstanding qty. Fully returned with no settlements
     * cancels the performa; leftover outstanding stays open/partial.
     *
     * @param  array<string, mixed>  $data
     */
    public function returnUnpaid(Performa $performa, array $data, User $actor): Performa
    {
        if ((int) $performa->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        if (! $performa->isOpen()) {
            throw ValidationException::withMessages([
                'performa' => 'This performa has no unpaid goods to return.',
            ]);
        }

        return DB::transaction(function () use ($performa, $data, $actor): Performa {
            $performa = Performa::query()->whereKey($performa->id)->lockForUpdate()->with('items')->firstOrFail();

            if (! $performa->status->isOpen()) {
                throw ValidationException::withMessages([
                    'performa' => 'This performa has no unpaid goods to return.',
                ]);
            }

            $requested = $this->requestedReturnQty($performa, $data);
            $returnedAny = false;

            foreach ($performa->items as $item) {
                $qty = $requested[$item->id] ?? 0;
                if ($qty < 1) {
                    continue;
                }

                $outstanding = $item->outstandingQuantity();
                if ($qty > $outstanding) {
                    throw ValidationException::withMessages([
                        "lines.{$item->id}" => "{$item->item_code_snapshot} has only {$outstanding} unpaid.",
                    ]);
                }

                $this->applyReturnStock($item, $qty, $performa, $actor);
                $item->quantity_returned += $qty;
                $item->save();
                $returnedAny = true;
            }

            if (! $returnedAny) {
                throw ValidationException::withMessages([
                    'lines' => 'Select at least one unpaid line to return.',
                ]);
            }

            $this->refreshStatus($performa);

            $this->auditLog->log('performa.returned', $performa, null, [
                'performa_number' => $performa->performa_number,
                'lines' => $requested,
            ], $actor);

            return $this->loadDetail($performa->fresh());
        });
    }

    /**
     * Recalculate header status from line outstanding / settled totals.
     */
    public function refreshStatus(Performa $performa): void
    {
        $performa->load('items');

        $outstanding = 0;
        $settled = 0;

        foreach ($performa->items as $item) {
            $outstanding += $item->outstandingQuantity();
            $settled += (int) $item->quantity_settled;
        }

        if ($outstanding === 0) {
            $performa->status = $settled > 0 ? PerformaStatus::SETTLED : PerformaStatus::CANCELLED;
        } elseif ($settled > 0) {
            $performa->status = PerformaStatus::PARTIAL;
        } else {
            $performa->status = PerformaStatus::OPEN;
        }

        $performa->save();
    }

    /**
     * Workspace payload for the create screen (no prices).
     *
     * @return array<string, mixed>
     */
    public function emptyPayload(): array
    {
        return [
            'performa_date' => now()->toDateString(),
            'creditor_uuid' => '',
            'creditor' => null,
            'notes' => '',
            'lines' => [],
        ];
    }

    /**
     * Outstanding lines for the settle screen.
     *
     * @return list<array<string, mixed>>
     */
    public function outstandingPayload(Performa $performa): array
    {
        $performa = $this->loadDetail($performa);

        return $performa->items
            ->filter(fn (PerformaItem $item): bool => $item->outstandingQuantity() > 0)
            ->map(fn (PerformaItem $item): array => $this->outstandingLinePayload($item))
            ->values()
            ->all();
    }

    /**
     * One outstanding line for the settle workspace. Jewellery reuses the
     * live item so weight, purity, and stone composition are available for
     * the same gold-rate / making calculator as quotations.
     *
     * @return array<string, mixed>
     */
    private function outstandingLinePayload(PerformaItem $item): array
    {
        $base = [
            'id' => $item->id,
            'line_type' => $item->line_type->value,
            'item_code_snapshot' => $item->item_code_snapshot,
            'name_snapshot' => $item->name_snapshot,
            'category_snapshot' => $item->category_snapshot,
            'metal_snapshot' => $item->metal_snapshot,
            'purity_snapshot' => $item->purity_snapshot,
            'gross_weight_snapshot' => (string) $item->gross_weight_snapshot,
            'net_weight_snapshot' => (string) $item->net_weight_snapshot,
            'quantity_issued' => $item->quantity_issued,
            'quantity_settled' => $item->quantity_settled,
            'quantity_returned' => $item->quantity_returned,
            'outstanding' => $item->outstandingQuantity(),
            'stones' => [],
        ];

        if ($item->line_type === LineType::JEWELLERY_ITEM && $item->jewelleryItem) {
            $live = $this->lookupService->jewelleryPayload($item->jewelleryItem);

            return array_merge($base, [
                'jewellery_item_id' => $live['jewellery_item_id'],
                'purity_id_snapshot' => $live['purity_id_snapshot'],
                'gross_weight_snapshot' => $live['gross_weight_snapshot'],
                'net_weight_snapshot' => $live['net_weight_snapshot'],
                'metal_snapshot' => $live['metal_snapshot'] ?? $item->metal_snapshot,
                'purity_snapshot' => $live['purity_snapshot'] ?? $item->purity_snapshot,
                'category_snapshot' => $live['category_snapshot'] ?? $item->category_snapshot,
                'gold_rate_applied' => '',
                'making_charge_type' => MakingChargeType::NONE->value,
                'making_charge_value' => '',
                'stones' => $live['stones'],
            ]);
        }

        return array_merge($base, [
            'stone_id' => $item->stone_id,
            'price_per_piece' => '',
            'pay_qty' => '',
        ]);
    }

    /**
     * Prefill the create payload when the user arrived from a creditor profile.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withSelectedCreditor(array $payload, ?string $uuid): array
    {
        $uuid = trim((string) $uuid);
        if ($uuid === '') {
            return $payload;
        }

        $creditor = Creditor::query()->active()->where('uuid', $uuid)->first();
        if (! $creditor) {
            return $payload;
        }

        $payload['creditor_uuid'] = $creditor->uuid;
        $payload['creditor'] = [
            'uuid' => $creditor->uuid,
            'name' => $creditor->name,
            'phone' => $creditor->phone,
            'creditor_code' => $creditor->creditor_code,
        ];

        return $payload;
    }

    /**
     * Resolve a live creditor on the actor's branch. Inactive or foreign
     * creditors are refused so a performa cannot attach a sales customer
     * or a deactivated party.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolveCreditorId(array $data, User $actor): ?int
    {
        $uuid = trim((string) ($data['creditor_uuid'] ?? ''));
        if ($uuid === '') {
            return null;
        }

        $id = Creditor::query()
            ->active()
            ->where('uuid', $uuid)
            ->where('branch_id', $actor->branch_id)
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertLinesAvailable(array $lines, User $actor): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item to the performa.']);
        }

        $jewelleryIds = [];

        foreach ($lines as $index => $line) {
            $lineNum = $index + 1;
            $type = LineType::from((string) $line['line_type']);

            if ($type === LineType::JEWELLERY_ITEM) {
                $itemId = (int) ($line['jewellery_item_id'] ?? 0);
                if (isset($jewelleryIds[$itemId])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => "{$line['item_code_snapshot']} is already on this performa.",
                    ]);
                }
                $jewelleryIds[$itemId] = true;

                $item = JewelleryItem::query()
                    ->where('branch_id', $actor->branch_id)
                    ->whereKey($itemId)
                    ->lockForUpdate()
                    ->first();

                if (! $item || $item->status !== JewelleryItemStatus::AVAILABLE) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => "Line {$lineNum}: {$line['item_code_snapshot']} is not available.",
                    ]);
                }
            } else {
                $stoneId = (int) ($line['stone_id'] ?? 0);
                $qty = max(1, (int) ($line['quantity'] ?? 1));
                $stone = Stone::query()
                    ->where('branch_id', $actor->branch_id)
                    ->whereKey($stoneId)
                    ->lockForUpdate()
                    ->first();

                if (! $stone || (int) $stone->quantity < $qty) {
                    $available = $stone ? (int) $stone->quantity : 0;
                    throw ValidationException::withMessages([
                        "lines.{$index}" => "Line {$lineNum}: {$line['item_code_snapshot']} has only {$available} in stock, {$qty} requested.",
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function writeLine(Performa $performa, array $line, int $index): PerformaItem
    {
        $type = LineType::from((string) $line['line_type']);
        $qty = $type === LineType::JEWELLERY_ITEM ? 1 : max(1, (int) ($line['quantity'] ?? 1));

        return PerformaItem::query()->create([
            'performa_id' => $performa->id,
            'line_type' => $type,
            'jewellery_item_id' => $type === LineType::JEWELLERY_ITEM ? ($line['jewellery_item_id'] ?? null) : null,
            'stone_id' => $type === LineType::LOOSE_STONE ? ($line['stone_id'] ?? null) : null,
            'item_code_snapshot' => $line['item_code_snapshot'] ?? '',
            'name_snapshot' => $line['name_snapshot'] ?? '',
            'category_snapshot' => $line['category_snapshot'] ?? null,
            'metal_snapshot' => $line['metal_snapshot'] ?? null,
            'purity_snapshot' => $line['purity_snapshot'] ?? null,
            'gross_weight_snapshot' => $line['gross_weight_snapshot'] ?? null,
            'net_weight_snapshot' => $line['net_weight_snapshot'] ?? null,
            'quantity_issued' => $qty,
            'quantity_settled' => 0,
            'quantity_returned' => 0,
            'sort_order' => $index,
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function applyIssueStock(array $line, Performa $performa, User $actor): void
    {
        $type = LineType::from((string) $line['line_type']);

        if ($type === LineType::JEWELLERY_ITEM) {
            $item = JewelleryItem::query()->lockForUpdate()->findOrFail($line['jewellery_item_id']);
            $this->jewelleryItemService->markIssuedOnCredit($item, $performa->performa_number, $actor);

            return;
        }

        $stone = Stone::query()->lockForUpdate()->findOrFail($line['stone_id']);
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $this->stoneStockService->apply(
            $stone,
            StoneMovementType::ISSUED_ON_CREDIT,
            -$qty,
            $actor,
            $performa,
            'Issued on performa '.$performa->performa_number
        );
    }

    private function applyReturnStock(PerformaItem $item, int $qty, Performa $performa, User $actor): void
    {
        if ($item->line_type === LineType::JEWELLERY_ITEM) {
            if ($qty !== 1 || $item->outstandingQuantity() !== 1) {
                throw ValidationException::withMessages([
                    "lines.{$item->id}" => 'A jewellery piece must be returned in full.',
                ]);
            }

            $jewellery = JewelleryItem::query()->lockForUpdate()->findOrFail($item->jewellery_item_id);
            $this->jewelleryItemService->restoreFromCredit($jewellery, $performa->performa_number, $actor);

            return;
        }

        $stone = Stone::query()->lockForUpdate()->findOrFail($item->stone_id);
        $this->stoneStockService->apply(
            $stone,
            StoneMovementType::RETURNED_FROM_CREDIT,
            $qty,
            $actor,
            $performa,
            'Returned from performa '.$performa->performa_number
        );
    }

    /**
     * Map of performa_item_id => qty to return. Missing keys mean "all outstanding"
     * when return_all is set.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function requestedReturnQty(Performa $performa, array $data): array
    {
        $map = [];

        if (! empty($data['return_all'])) {
            foreach ($performa->items as $item) {
                $outstanding = $item->outstandingQuantity();
                if ($outstanding > 0) {
                    $map[$item->id] = $outstanding;
                }
            }

            return $map;
        }

        foreach ($data['lines'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['performa_item_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            if ($id > 0 && $qty > 0) {
                $map[$id] = $qty;
            }
        }

        return $map;
    }
}
