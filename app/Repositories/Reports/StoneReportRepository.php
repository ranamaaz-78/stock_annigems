<?php

namespace App\Repositories\Reports;

use App\Enums\StoneMovementType;
use App\Enums\StonePricingMode;
use App\Models\Stone;
use App\Models\StoneIntake;
use App\Models\StoneStockMovement;
use App\Support\Reports\ReportFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * StoneReportRepository
 *
 * Loose stone reporting. Stones are the one fungible inventory in this system:
 * one row per stone *type*, carrying a quantity. Everything here is therefore
 * counted and weighed rather than enumerated piece by piece.
 *
 * Like jewellery stock, these read live records because they describe present
 * holdings. Stock is valued at each lot's own rate (pieces x piece price, or
 * grams x price per gram); that is purchase cost, never a selling price.
 */
class StoneReportRepository extends ReportRepository
{
    /**
     * Every registered lot with what is in hand, in the lot's own unit, and
     * what that stock is worth.
     *
     * Pieces and grams are kept apart: a lot priced by gram holds its stock in
     * `weight_grams` and always has `quantity` 0, so adding the two would be
     * meaningless. `value` is the live worth of what is in hand at the lot's
     * own rate (pieces x piece price, or grams x price per gram), not the
     * original cost of the lot, which only ever grows with intakes.
     *
     * @return Collection<int, array{
     *     uuid: string, stone_code: string, name: string, shape: string,
     *     carat: float, quantity: int, grams: float, weighed: bool, pricing: string,
     *     unit: string, on_hand: float, on_hand_label: string, rate: float, rate_label: string,
     *     value: float, lot_cost: float, total_carat: float, is_active: bool
     * }>
     */
    public function stockSummary(?int $branchId): Collection
    {
        return $this->lotQuery($branchId)
            ->orderBy('stones.shape')
            ->orderBy('stones.name')
            ->get($this->lotColumns())
            ->map(fn ($row): array => $this->lot($row));
    }

    /**
     * Totals per shape: how many lots, how many pieces, how many grams, how
     * much carat and what it is all worth.
     *
     * @return Collection<int, array{shape: string, stone_count: int, quantity: int, grams: float, total_carat: float, value: float}>
     */
    public function stockByShape(?int $branchId): Collection
    {
        return $this->stockSummary($branchId)
            ->groupBy(fn (array $lot): string => $lot['shape'] === 'â€”' || $lot['shape'] === '' ? __('Unspecified') : $lot['shape'])
            ->map(fn (Collection $lots, string $shape): array => [
                'shape' => $shape,
                'stone_count' => $lots->count(),
                'quantity' => (int) $lots->sum('quantity'),
                'grams' => round((float) $lots->sum('grams'), 3),
                'total_carat' => (float) $lots->sum('total_carat'),
                'value' => round((float) $lots->sum('value'), 2),
            ])
            ->sortByDesc(fn (array $row): float => $row['value'] * 1000 + $row['quantity'])
            ->values();
    }

    /**
     * Lots at zero or at/below the low threshold, each judged in its own unit:
     * pieces for piece and carat lots, grams for gram lots.
     *
     * Inactive (deactivated) lots are excluded: a deactivated packet is not
     * something to reorder.
     *
     * @return Collection<int, array{
     *     uuid: string, stone_code: string, name: string, shape: string, carat: float,
     *     quantity: int, grams: float, weighed: bool, unit: string, on_hand_label: string, severity: string
     * }>
     */
    public function zeroAndLowStock(?int $branchId): Collection
    {
        $pieceLimit = (int) config('annigems.reports.low_stone_threshold', 5);
        $gramLimit = (float) config('annigems.reports.low_stone_weight_threshold', 10);

        return $this->lotQuery($branchId)
            ->where('stones.is_active', true)
            ->get($this->lotColumns())
            ->map(fn ($row): array => $this->lot($row))
            ->filter(fn (array $lot): bool => $lot['weighed']
                ? $lot['grams'] <= $gramLimit
                : $lot['quantity'] <= $pieceLimit)
            ->map(fn (array $lot): array => $lot + [
                // Zero is out of stock; anything else here is merely low.
                'severity' => $lot['on_hand'] <= 0 ? __('Out of stock') : __('Low stock'),
            ])
            ->sortBy([
                fn (array $a, array $b): int => ($a['on_hand'] <=> $b['on_hand']),
                fn (array $a, array $b): int => strcmp($a['name'], $b['name']),
            ])
            ->values();
    }

    /**
     * Count of active lots with nothing left: no pieces, or no grams for a
     * gram lot (whose piece count is always zero and means nothing).
     */
    public function zeroStockCount(?int $branchId): int
    {
        return $this->lotQuery($branchId)
            ->where('stones.is_active', true)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $pieces): void {
                    $pieces->whereNotIn('stones.pricing_mode', StonePricingMode::weighedValues())
                        ->where('stones.quantity', 0);
                })->orWhere(function (Builder $grams): void {
                    $grams->whereIn('stones.pricing_mode', StonePricingMode::weighedValues())
                        ->where(fn (Builder $w) => $w->whereNull('stones.weight_grams')->orWhere('stones.weight_grams', '<=', 0));
                });
            })
            ->count();
    }

    /**
     * Total number of individual stones held in pieces (gram lots are not
     * counted in pieces; see totalStockGrams).
     */
    public function totalStonesInStock(?int $branchId): int
    {
        return $this->toInt($this->lotQuery($branchId)
            ->whereNotIn('stones.pricing_mode', StonePricingMode::weighedValues())
            ->sum('stones.quantity'));
    }

    /**
     * Total grams held across gram-priced lots.
     */
    public function totalStockGrams(?int $branchId): float
    {
        return round($this->toFloat($this->lotQuery($branchId)
            ->where('stones.pricing_mode', StonePricingMode::PER_GRAM->value)
            ->sum('stones.weight_grams')), 3);
    }

    /**
     * Total carat held across the per-carat lots (their carats are their stock).
     */
    public function totalCaratInStock(?int $branchId): float
    {
        return $this->toFloat(
            $this->lotQuery($branchId)
                ->where('stones.pricing_mode', StonePricingMode::PER_CARAT->value)
                ->sum('stones.weight_grams')
        );
    }

    /**
     * What all the stone in hand is worth at each lot's own rate.
     */
    public function stockValue(?int $branchId): float
    {
        return round((float) $this->stockSummary($branchId)->sum('value'), 2);
    }

    /**
     * Intake batches in the period, with the performer's role at the time.
     *
     * @return Collection<int, array{
     *     uuid: string, intake_number: string, intake_date: string,
     *     stone_types: int, quantity: int, total_carat: float,
     *     actor_name: ?string, actor_role: ?string,
     *     actor_status: ?string, actor_deleted_at: ?string
     * }>
     */
    public function intakeHistory(ReportFilter $filter): Collection
    {
        $query = $this->unscoped(StoneIntake::class)
            ->leftJoin('users', 'users.id', '=', 'stone_intakes.performed_by');

        $this->scopeToBranch($query, $filter->branchId, 'stone_intakes');
        $this->scopeToDateRange($query, $filter, 'stone_intakes.intake_date');

        return $query
            ->orderByDesc('stone_intakes.intake_date')
            ->orderByDesc('stone_intakes.id')
            ->get([
                'stone_intakes.uuid',
                'stone_intakes.intake_number',
                'stone_intakes.intake_date',
                'stone_intakes.total_stone_types',
                'stone_intakes.total_quantity',
                'stone_intakes.total_carat',
                'stone_intakes.total_weight_grams',
                'stone_intakes.performed_by_role as actor_role',
                'users.name as actor_name',
                'users.status as actor_status',
                'users.deleted_at as actor_deleted_at',
            ])
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'intake_number' => (string) $row->intake_number,
                'intake_date' => (string) $row->intake_date,
                'stone_types' => $this->toInt($row->total_stone_types),
                'quantity' => $this->toInt($row->total_quantity),
                'total_carat' => $this->toFloat($row->total_carat),
                'weight_grams' => round($this->toFloat($row->total_weight_grams), 3),
                'actor_name' => $row->actor_name,
                'actor_role' => $row->actor_role,
                'actor_status' => $row->actor_status,
                'actor_deleted_at' => $row->actor_deleted_at,
            ]);
    }

    /**
     * Recent intake batches for the Inventory Staff dashboard.
     *
     * @return Collection<int, array{uuid: string, intake_number: string, intake_date: string, quantity: int, total_carat: float}>
     */
    public function recentIntakes(?int $branchId, int $limit): Collection
    {
        $query = $this->unscoped(StoneIntake::class);

        $this->scopeToBranch($query, $branchId, 'stone_intakes');

        return $query
            ->orderByDesc('intake_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['uuid', 'intake_number', 'intake_date', 'total_quantity', 'total_weight_grams', 'total_carat'])
            ->map(fn (StoneIntake $intake): array => [
                'uuid' => (string) $intake->uuid,
                'intake_number' => (string) $intake->intake_number,
                'intake_date' => (string) $intake->intake_date?->toDateString(),
                'quantity' => (int) $intake->total_quantity,
                'weight_grams' => round((float) $intake->total_weight_grams, 3),
                'total_carat' => (float) $intake->total_carat,
            ]);
    }

    /**
     * Consumption in the period, per stone, split by where it went.
     *
     * Only out-flows count (the signed change is negative). Pieces and grams
     * are kept apart because a gram lot's movements carry `weight_change` and
     * a zero piece change. A quotation hold is not consumption, so reserve and
     * release rows are ignored. What came back to stock (voided sales,
     * cancelled delivery notes, returns from credit, jewellery deleted) is
     * reported on its own as `back` and never netted off the out-flows.
     *
     * @return Collection<int, array<string, int|float|string>>
     */
    public function consumption(ReportFilter $filter): Collection
    {
        $query = $this->movements($filter)
            ->join('stones', 'stones.id', '=', 'stone_stock_movements.stone_id')
            ->whereNotIn('stone_stock_movements.type', [
                StoneMovementType::RESERVED_ON_QUOTATION->value,
                StoneMovementType::RELEASED_FROM_QUOTATION->value,
            ])
            ->selectRaw("stones.stone_code, stones.name, COALESCE(NULLIF(stones.shape, ''), 'Unspecified') as shape")
            ->selectRaw('stone_stock_movements.type as movement_type')
            ->selectRaw('COALESCE(SUM(stone_stock_movements.quantity_change), 0) as net_change')
            ->selectRaw('COALESCE(SUM(stone_stock_movements.weight_change), 0) as net_weight')
            ->groupByRaw("stones.stone_code, stones.name, COALESCE(NULLIF(stones.shape, ''), 'Unspecified'), stone_stock_movements.type");

        $buckets = ['used_in_item', 'sold', 'delivered', 'on_credit', 'written_off', 'other_out'];
        $byStone = [];

        foreach ($query->get() as $row) {
            $code = (string) $row->stone_code;

            if (! isset($byStone[$code])) {
                $byStone[$code] = ['stone_code' => $code, 'name' => (string) $row->name, 'shape' => $row->shape === 'Unspecified' ? __('Unspecified') : (string) $row->shape,
                    'back' => 0, 'back_g' => 0.0, 'total_out' => 0, 'total_out_g' => 0.0];
                foreach ($buckets as $bucket) {
                    $byStone[$code][$bucket] = 0;
                    $byStone[$code][$bucket.'_g'] = 0.0;
                }
            }

            $pieces = $this->toInt($row->net_change);
            $grams = round($this->toFloat($row->net_weight), 3);
            $type = $this->scalar($row->movement_type);

            // Inward movements are not consumption; they are what came back.
            if ($pieces > 0 || $grams > 0) {
                $byStone[$code]['back'] += max(0, $pieces);
                $byStone[$code]['back_g'] = round($byStone[$code]['back_g'] + max(0.0, $grams), 3);

                continue;
            }

            if ($pieces === 0 && $grams === 0.0) {
                continue;
            }

            $bucket = match ($type) {
                StoneMovementType::USED_IN_ITEM->value => 'used_in_item',
                StoneMovementType::SOLD->value => 'sold',
                StoneMovementType::DELIVERED->value => 'delivered',
                StoneMovementType::ISSUED_ON_CREDIT->value => 'on_credit',
                StoneMovementType::DAMAGED->value,
                StoneMovementType::LOST->value,
                StoneMovementType::ADJUSTED->value => 'written_off',
                default => 'other_out',
            };

            $byStone[$code][$bucket] += abs($pieces);
            $byStone[$code][$bucket.'_g'] = round($byStone[$code][$bucket.'_g'] + abs($grams), 3);
            $byStone[$code]['total_out'] += abs($pieces);
            $byStone[$code]['total_out_g'] = round($byStone[$code]['total_out_g'] + abs($grams), 3);
        }

        return collect($byStone)
            ->filter(fn (array $row): bool => $row['total_out'] > 0 || $row['total_out_g'] > 0 || $row['back'] > 0 || $row['back_g'] > 0)
            ->sortByDesc(fn (array $row): float => $row['total_out'] + $row['total_out_g'])
            ->values();
    }

    /**
     * Full movement ledger, newest first.
     *
     * quantity_before and quantity_after are returned on every row because they
     * are the reconciliation check: for one stone, each row's before must equal
     * the previous row's after. A gram lot reconciles on its weight columns
     * instead (its piece count never moves).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function movementLedger(ReportFilter $filter, int $limit): Collection
    {
        return $this->movements($filter)
            ->join('stones', 'stones.id', '=', 'stone_stock_movements.stone_id')
            ->leftJoin('users', 'users.id', '=', 'stone_stock_movements.performed_by')
            ->orderByDesc('stone_stock_movements.created_at')
            ->orderByDesc('stone_stock_movements.id')
            ->limit($limit)
            ->get([
                'stones.stone_code',
                'stones.name as stone_name',
                'stone_stock_movements.type',
                'stone_stock_movements.quantity_change',
                'stone_stock_movements.quantity_before',
                'stone_stock_movements.quantity_after',
                'stone_stock_movements.weight_change',
                'stone_stock_movements.weight_before',
                'stone_stock_movements.weight_after',
                'stone_stock_movements.notes',
                'stone_stock_movements.performed_by_role as actor_role',
                'stone_stock_movements.created_at',
                'users.name as actor_name',
                'users.status as actor_status',
                'users.deleted_at as actor_deleted_at',
            ])
            ->map(fn ($row): array => [
                'stone_code' => (string) $row->stone_code,
                'stone_name' => (string) $row->stone_name,
                'type' => $this->scalar($row->type),
                'quantity_change' => (int) $row->quantity_change,
                'quantity_before' => (int) $row->quantity_before,
                'quantity_after' => (int) $row->quantity_after,
                // Present only on gram-lot movements.
                'weighed' => $row->weight_change !== null,
                'weight_change' => $row->weight_change === null ? null : (float) $row->weight_change,
                'weight_before' => $row->weight_before === null ? null : (float) $row->weight_before,
                'weight_after' => $row->weight_after === null ? null : (float) $row->weight_after,
                'notes' => $row->notes,
                'actor_name' => $row->actor_name,
                'actor_role' => $row->actor_role,
                'actor_status' => $row->actor_status,
                'actor_deleted_at' => $row->actor_deleted_at,
                'created_at' => (string) $row->created_at,
            ]);
    }

    /**
     * Movement volume per type, in pieces and in grams.
     *
     * @return Collection<int, array{type: string, movements: int, quantity_in: int, quantity_out: int, grams_in: float, grams_out: float}>
     */
    public function movementVolumeByType(ReportFilter $filter): Collection
    {
        return $this->movements($filter)
            ->selectRaw('stone_stock_movements.type')
            ->selectRaw('COUNT(*) as movements')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END), 0) as quantity_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity_change < 0 THEN -quantity_change ELSE 0 END), 0) as quantity_out')
            ->selectRaw('COALESCE(SUM(CASE WHEN weight_change > 0 THEN weight_change ELSE 0 END), 0) as grams_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN weight_change < 0 THEN -weight_change ELSE 0 END), 0) as grams_out')
            ->groupBy('stone_stock_movements.type')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row): array => [
                'type' => $this->scalar($row->type),
                'movements' => $this->toInt($row->movements),
                'quantity_in' => $this->toInt($row->quantity_in),
                'quantity_out' => $this->toInt($row->quantity_out),
                'grams_in' => round($this->toFloat($row->grams_in), 3),
                'grams_out' => round($this->toFloat($row->grams_out), 3),
            ]);
    }

    /**
     * Live (not deleted) lots of the branch, with the columns every stock
     * figure needs. A deleted lot is gone from stock; its history stays in the
     * ledger.
     */
    private function lotQuery(?int $branchId): Builder
    {
        return $this->stones($branchId);
    }

    /**
     * @return list<string>
     */
    private function lotColumns(): array
    {
        return [
            'stones.uuid', 'stones.stone_code', 'stones.name', 'stones.shape', 'stones.carat', 'stones.quantity',
            'stones.weight_grams', 'stones.pricing_mode', 'stones.price_per_carat', 'stones.price_per_piece',
            'stones.price_per_gram', 'stones.piece_price', 'stones.total_cost', 'stones.is_active',
        ];
    }

    /**
     * One lot row in report terms: its own unit, rate and live value.
     *
     * @return array<string, mixed>
     */
    private function lot(object $row): array
    {
        $mode = $row->pricing_mode instanceof StonePricingMode ? $row->pricing_mode : StonePricingMode::tryFrom((string) $row->pricing_mode);
        $weighed = $mode?->isWeighed() ?? false;
        $isCarat = $mode === StonePricingMode::PER_CARAT;
        $pieces = (int) $row->quantity;
        $grams = round((float) $row->weight_grams, 3);
        $carat = (float) $row->carat;

        [$rate, $unit, $value] = match ($mode) {
            StonePricingMode::PER_GRAM => [(float) $row->price_per_gram, 'g', $grams * (float) $row->price_per_gram],
            StonePricingMode::PER_CARAT => [(float) $row->price_per_carat, 'ct', $grams * (float) $row->price_per_carat],
            default => [(float) $row->price_per_piece, 'pc', $pieces * (float) $row->price_per_piece],
        };

        return [
            'uuid' => (string) $row->uuid,
            'stone_code' => (string) $row->stone_code,
            'name' => (string) $row->name,
            'shape' => (string) ($row->shape ?? 'â€”'),
            'carat' => $carat,
            'quantity' => $weighed ? 0 : $pieces,
            'grams' => $weighed && ! $isCarat ? $grams : 0.0,
            'weighed' => $weighed,
            'pricing' => $mode?->label() ?? 'â€”',
            'unit' => $mode?->isWeighed() ? $mode->weightUnit() : 'pcs',
            'on_hand' => $weighed ? $grams : (float) $pieces,
            'on_hand_label' => $weighed ? rtrim(rtrim(number_format($grams, 3, '.', ''), '0'), '.').' '.$mode->weightUnit() : $pieces.' pcs',
            'rate' => $rate,
            'rate_unit' => $unit,
            'value' => round($value, 2),
            'lot_cost' => (float) $row->total_cost,
            'total_carat' => $isCarat ? $grams : ($weighed ? 0.0 : $carat * $pieces),
            'is_active' => (bool) $row->is_active,
        ];
    }

    /**
     * Base live-stone query, branch-scoped explicitly.
     */
    private function stones(?int $branchId): Builder
    {
        $query = $this->unscoped(Stone::class);

        $this->scopeToBranch($query, $branchId, 'stones');

        return $query;
    }

    /**
     * Base movement query for the filter's branch, range and optional filters.
     */
    private function movements(ReportFilter $filter): Builder
    {
        $query = $this->unscoped(StoneStockMovement::class);

        $this->scopeToBranch($query, $filter->branchId, 'stone_stock_movements');
        $this->scopeToRange($query, $filter, 'stone_stock_movements.created_at');

        if ($filter->hasOption('stone_id')) {
            $query->where('stone_stock_movements.stone_id', $filter->option('stone_id'));
        }

        if ($filter->hasOption('movement_type')) {
            $query->where('stone_stock_movements.type', $filter->option('movement_type'));
        }

        if ($filter->hasOption('staff_id')) {
            $query->where('stone_stock_movements.performed_by', $filter->option('staff_id'));
        }

        return $query;
    }
}
