<?php

namespace App\Services;

use App\Enums\StoneMovementType;
use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use App\Models\Stone;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StoneService
 *
 * Registration and edits for branch-scoped stone lots. Money always comes
 * from StonePricingService and quantity always from StoneStockService, so a
 * controller can never write either directly.
 */
class StoneService
{
    public function __construct(
        private CodeSequenceService $codeSequenceService,
        private AuditLogService $auditLogService,
        private StonePricingService $pricingService,
        private StoneStockService $stoneStockService,
        private StoneBarcodeGenerator $barcodeGenerator,
        private StoneCategoryService $categoryService,
    ) {}

    /**
     * Paginated, filtered list for the current branch (global scope).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Stone::query()->with('image')->orderBy('stone_code');

        $this->applyFilters($query, $filters);

        return $query->paginate(per_page())->withQueryString();
    }

    /**
     * Register one lot.
     *
     * Also opens the stock ledger: the piece count from the sheet is applied
     * as an INTAKE movement so on-hand and history agree from row one.
     *
     * @param  array<string, mixed>  $data Validated lot fields
     */
    public function register(array $data, User $actor): Stone
    {
        return DB::transaction(function () use ($data, $actor): Stone {
            $stone = $this->createLot($data, $actor);

            $this->auditLogService->log('stone.created', $stone, null, $this->auditPayload($stone), $actor);

            return $stone->refresh();
        });
    }

    /**
     * Create many lots in one transaction (Excel import).
     *
     * All or nothing: a single unusable row aborts the batch so a partial
     * inventory is never left behind.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<Stone>
     */
    public function registerMany(array $rows, User $actor): array
    {
        return DB::transaction(function () use ($rows, $actor): array {
            $created = [];

            foreach ($rows as $index => $row) {
                $stone = $this->createLot($row, $actor, $index);
                $this->auditLogService->log('stone.created', $stone, null, $this->auditPayload($stone), $actor);
                $created[] = $stone;
            }

            return $created;
        });
    }

    /**
     * Update lot details and recalculate money.
     *
     * Quantity is corrected through the ledger when the piece count changes,
     * so the movement history still explains the on-hand figure.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Stone $stone, array $data, User $actor): Stone
    {
        return DB::transaction(function () use ($stone, $data, $actor): Stone {
            $old = $this->auditPayload($stone);
            $pricing = $this->assertCalculable($data);
            $previousRate = $stone->rate();
            $previousMode = $stone->pricing_mode;

            $stone->fill(array_merge($this->lotAttributes($data), [
                'piece_price' => $pricing['piece_price'],
                'total_cost' => $pricing['total_cost'],
                'cost_formula' => $pricing['cost_formula'],
                'is_active' => (bool) ($data['is_active'] ?? $stone->is_active),
            ]));
            $this->keepLastRate($stone, $previousRate, $previousMode);
            $stone->save();

            // Renaming a lot moves it to the folder for its new material.
            $this->categoryService->syncFor($stone, $actor);

            $this->syncPieceCount($stone, $data, $actor, 'Piece count corrected on edit');
            $this->syncWeight($stone, $data, $actor, 'Weight corrected on edit');

            $this->auditLogService->log('stone.updated', $stone, $old, $this->auditPayload($stone), $actor);

            return $stone->refresh();
        });
    }

    /**
     * Hide a lot from new intake and sale without deleting history.
     */
    public function deactivate(Stone $stone, User $actor): Stone
    {
        $old = $this->auditPayload($stone);
        $stone->is_active = false;
        $stone->save();
        $this->auditLogService->log('stone.deactivated', $stone, $old, $this->auditPayload($stone), $actor);

        return $stone;
    }

    /**
     * Re-offer a deactivated lot.
     */
    public function activate(Stone $stone, User $actor): Stone
    {
        $old = $this->auditPayload($stone);
        $stone->is_active = true;
        $stone->save();
        $this->auditLogService->log('stone.activated', $stone, $old, $this->auditPayload($stone), $actor);

        return $stone;
    }

    /**
     * Branch-scoped barcode / stone-code lookup for the scan page.
     *
     * Distinguishes "exists on another branch" from "unknown" so operators
     * are not told a packet does not exist when it belongs elsewhere.
     *
     * @return array{status: string, stone?: Stone}
     */
    public function lookupScan(string $code, int $branchId): array
    {
        $code = trim($code);

        $inBranch = Stone::query()
            ->where(function (Builder $query) use ($code): void {
                $query->where('barcode', $code)->orWhere('stone_code', $code);
            })
            ->first();

        if ($inBranch) {
            if (! $inBranch->is_active) {
                return ['status' => 'inactive', 'stone' => $inBranch];
            }

            return ['status' => 'found', 'stone' => $inBranch];
        }

        $elsewhere = Stone::query()
            ->withoutGlobalScopes()
            ->where(function (Builder $query) use ($code): void {
                $query->where('barcode', $code)->orWhere('stone_code', $code);
            })
            ->first();

        if ($elsewhere && (int) $elsewhere->branch_id !== $branchId) {
            return ['status' => 'other_branch'];
        }

        return ['status' => 'missing'];
    }

    /**
     * Search active lots by name or code for the add-by-name control.
     *
     * @return \Illuminate\Support\Collection<int, Stone>
     */
    public function searchActive(string $term, int $limit = 15)
    {
        $term = trim($term);

        return Stone::query()
            ->where('is_active', true)
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.$term.'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('stone_code', 'like', $like)
                        ->orWhere('barcode', 'like', $like)
                        ->orWhere('tray_no', 'like', $like);
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Public JSON shape for a lot (uuid, never integer id).
     *
     * @return array<string, mixed>
     */
    public function toScanPayload(Stone $stone): array
    {
        return [
            'uuid' => $stone->uuid,
            'stone_code' => $stone->stone_code,
            'barcode' => $stone->barcode,
            'name' => $stone->name,
            'shape' => $stone->shape,
            'dimension' => $stone->dimension,
            'carat' => $stone->carat === null ? null : (string) $stone->carat,
            'quantity' => (int) $stone->quantity,
            'pairs' => (int) $stone->pairs,
            'weight_grams' => $stone->weight_grams === null ? null : (string) $stone->weight_grams,
            'pricing_mode' => $stone->pricing_mode?->value,
            'pricing_mode_label' => $stone->pricing_mode?->label(),
            'piece_price' => $stone->piece_price === null ? null : (string) $stone->piece_price,
            'total_cost' => (string) $stone->total_cost,
            'cost_formula' => $stone->cost_formula,
            'status' => $stone->status?->value,
            'tray_no' => $stone->tray_no,
            'image_code' => $stone->image_code,
            'image_url' => $stone->image ? $stone->image->url() : null,
            'remarks' => $stone->remarks,
            'is_active' => $stone->is_active,
        ];
    }

    /**
     * Insert one lot with its code, barcode, calculated money, and stock.
     *
     * @param  array<string, mixed>  $data
     * @param  int|null  $rowIndex Import row number for field-level errors
     */
    private function createLot(array $data, User $actor, ?int $rowIndex = null): Stone
    {
        $pricing = $this->assertCalculable($data, $rowIndex);

        $stone = Stone::query()->create(array_merge($this->lotAttributes($data), [
            'branch_id' => $actor->branch_id,
            // The material name is also its folder: an unseen name opens a
            // new one, a known name files the lot with its siblings.
            'stone_category_id' => $this->categoryService->resolve((string) $data['name'], $actor)?->id,
            'stone_code' => $this->codeSequenceService->nextStoneCode(),
            // A packet barcode is its own random number, unrelated to the STN
            // code. Branches that do not label packets can switch it off.
            'barcode' => setting('stone_barcode_enabled') ? $this->barcodeGenerator->generate() : null,
            'piece_price' => $pricing['piece_price'],
            'total_cost' => $pricing['total_cost'],
            'cost_formula' => $pricing['cost_formula'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]));

        $this->syncPieceCount($stone, $data, $actor, 'Opening stock from registration');
        $this->syncWeight($stone, $data, $actor, 'Opening stock from registration');

        return $stone;
    }

    /**
     * Sheet fields, normalised. Money, quantity and weight are deliberately
     * absent: they are written through the pricing and stock services.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function lotAttributes(array $data): array
    {
        $mode = $this->mode($data);

        return [
            'name' => trim((string) $data['name']),
            'shape' => $this->nullableString($data['shape'] ?? null),
            'dimension' => $this->nullableString($data['dimension'] ?? null),
            // Each mode keeps only the figures its formula uses, so a stale
            // rate from another mode can never resurface in a later edit.
            'carat' => $mode === StonePricingMode::PER_CARAT ? $this->threeDecimals($data['carat']) : null,
            'pairs' => (int) ($data['pairs'] ?? 0),
            'pricing_mode' => $mode,
            'price_per_carat' => $mode === StonePricingMode::PER_CARAT ? $this->threeDecimals($data['price_per_carat']) : null,
            'price_per_piece' => $mode === StonePricingMode::PER_PIECE ? $this->threeDecimals($data['price_per_piece']) : null,
            'price_per_gram' => $mode === StonePricingMode::PER_GRAM ? $this->threeDecimals($data['price_per_gram']) : null,
            'status' => $this->status($data),
            'tray_no' => $this->nullableString($data['tray_no'] ?? null),
            'image_code' => $this->nullableString($data['image_code'] ?? null),
            'remarks' => $this->nullableString($data['remarks'] ?? null),
        ];
    }

    /**
     * Calculate money, refusing a lot whose figures do not support its mode.
     *
     * @param  array<string, mixed>  $data
     * @return array{piece_price: float|null, total_cost: float, cost_formula: string|null, issues: list<string>}
     */
    private function assertCalculable(array $data, ?int $rowIndex = null): array
    {
        $pricing = $this->pricingService->calculate($data);

        if ($pricing['issues'] !== []) {
            $field = $rowIndex === null ? 'pricing_mode' : "rows.{$rowIndex}.pricing_mode";

            throw ValidationException::withMessages([
                $field => $pricing['issues'],
            ]);
        }

        return $pricing;
    }

    /**
     * Move on-hand quantity to the lot's piece count through the ledger.
     *
     * Gram lots hold no pieces, so nothing is recorded for them.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncPieceCount(Stone $stone, array $data, User $actor, string $note): void
    {
        $target = max(0, (int) ($data['pieces'] ?? 0));
        $change = $target - (int) $stone->quantity;

        if ($change === 0) {
            return;
        }

        $this->stoneStockService->apply(
            $stone,
            $change > 0 ? StoneMovementType::INTAKE : StoneMovementType::ADJUSTED,
            $change,
            $actor,
            null,
            $note
        );
    }

    /**
     * Remember the price a lot is leaving behind.
     *
     * Only an edit that really changes the rate (or the mode it is quoted
     * in) counts: saving the same figures again must not overwrite the
     * last price with the current one.
     *
     * @param  string|null  $previousRate  The rate before the edit, in its own mode
     */
    private function keepLastRate(Stone $stone, ?string $previousRate, ?StonePricingMode $previousMode): void
    {
        if ($previousRate === null || $previousMode === null) {
            return;
        }

        $newRate = $stone->rate();
        $changed = $stone->pricing_mode !== $previousMode
            || $newRate === null
            || abs((float) $newRate - (float) $previousRate) > 0.00005;

        if (! $changed) {
            return;
        }

        $stone->last_rate = $previousRate;
        $stone->last_rate_mode = $previousMode;
        $stone->rate_changed_at = now();
    }

    /**
     * Move a gram lot's weight to the figure on the form through the ledger.
     *
     * Only a per-gram lot has a weight; any other mode ends at none. The
     * change is recorded as intake when stock opens and as an adjustment on
     * an edit, so the movement history still explains the grams on hand.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncWeight(Stone $stone, array $data, User $actor, string $note): void
    {
        $isGram = $this->mode($data) === StonePricingMode::PER_GRAM;
        $target = $isGram ? $this->threeDecimals($data['weight_grams'] ?? 0) : 0.0;
        $change = round($target - (float) ($stone->weight_grams ?? 0), 3);

        if ($change !== 0.0) {
            $this->stoneStockService->applyWeight(
                $stone,
                $change > 0 && $stone->wasRecentlyCreated ? StoneMovementType::INTAKE : StoneMovementType::ADJUSTED,
                $change,
                $actor,
                null,
                $note
            );
        }

        // A lot that is not weighed carries no weight at all, not zero grams.
        if (! $isGram && $stone->weight_grams !== null) {
            $stone->weight_grams = null;
            $stone->saveQuietly();
        }
    }

    /**
     * Pricing mode as an enum, defaulting to per piece.
     *
     * @param  array<string, mixed>  $data
     */
    private function mode(array $data): StonePricingMode
    {
        $mode = $data['pricing_mode'] ?? null;

        if ($mode instanceof StonePricingMode) {
            return $mode;
        }

        return StonePricingMode::tryFrom((string) $mode) ?? StonePricingMode::PER_PIECE;
    }

    /**
     * Lot status as an enum, defaulting to in stock.
     *
     * @param  array<string, mixed>  $data
     */
    private function status(array $data): StoneStatus
    {
        $status = $data['status'] ?? null;

        if ($status instanceof StoneStatus) {
            return $status;
        }

        return StoneStatus::tryFrom((string) $status) ?? StoneStatus::IN_STOCK;
    }

    /**
     * A figure at the stored scale (three decimals), as a number.
     *
     * The same rounding the pricing service applies, so the rate that is
     * saved is exactly the one the total was worked out from.
     */
    private function threeDecimals(mixed $value): float
    {
        return round((float) $value, StonePricingService::SCALE);
    }

    /**
     * Trimmed text, or null when the cell is blank.
     */
    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * @param  Builder<Stone>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $term = $filters['q'] ?? $filters['search'] ?? null;
        if (! empty($term)) {
            $like = '%'.$term.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('stone_code', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('tray_no', 'like', $like)
                    ->orWhere('dimension', 'like', $like);
            });
        }

        if (! empty($filters['pricing_mode'])) {
            $query->where('pricing_mode', $filters['pricing_mode']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['tray_no'])) {
            $query->where('tray_no', $filters['tray_no']);
        }

        if (! empty($filters['shape'])) {
            $query->where('shape', $filters['shape']);
        }

        // The folder being viewed, identified by its uuid like every other
        // record the URL can address.
        if (! empty($filters['category'])) {
            $query->whereHas('category', fn (Builder $inner) => $inner->where('uuid', $filters['category']));
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            // "0" must stay inactive — (bool) "0" is true in PHP.
            $query->where('is_active', (string) $filters['is_active'] === '1');
        }

        if (($filters['stock'] ?? '') === 'in') {
            $query->where('quantity', '>', 0);
        }
        if (($filters['stock'] ?? '') === 'out') {
            $query->where('quantity', '=', 0);
        }

        if (! empty($filters['carat_min'])) {
            $query->where('carat', '>=', $filters['carat_min']);
        }
        if (! empty($filters['carat_max'])) {
            $query->where('carat', '<=', $filters['carat_max']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(Stone $stone): array
    {
        return $stone->only([
            'uuid', 'stone_code', 'barcode', 'name', 'shape', 'dimension', 'carat',
            'pairs', 'weight_grams', 'pricing_mode', 'price_per_carat', 'price_per_piece',
            'price_per_gram', 'last_rate', 'piece_price', 'total_cost', 'status', 'tray_no', 'image_code', 'is_active',
        ]);
    }
}
