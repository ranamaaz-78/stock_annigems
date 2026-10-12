<?php

namespace App\Repositories\Reports;

use App\Enums\StoneMovementType;
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
 * holdings. There is no stone valuation figure — no stone price is captured
 * anywhere in the system, so none can honestly be reported.
 */
class StoneReportRepository extends ReportRepository
{
    /**
     * Quantity and carat in hand for every registered lot, grouped by shape.
     *
     * total_carat is quantity × per-piece carat, computed in SQL. Lots priced
     * by gram carry no carat, so they contribute nothing to that column.
     *
     * @return Collection<int, array{
     *     uuid: string, stone_code: string, name: string, shape: string,
     *     carat: float, quantity: int, total_carat: float, is_active: bool
     * }>
     */
    public function stockSummary(?int $branchId): Collection
    {
        return $this->stones($branchId)
            ->orderBy('stones.shape')
            ->orderBy('stones.name')
            ->get([
                'stones.uuid',
                'stones.stone_code',
                'stones.name',
                'stones.shape',
                'stones.carat',
                'stones.quantity',
                'stones.is_active',
            ])
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'stone_code' => (string) $row->stone_code,
                'name' => (string) $row->name,
                'shape' => (string) ($row->shape ?? '—'),
                'carat' => (float) $row->carat,
                'quantity' => (int) $row->quantity,
                'total_carat' => (float) $row->carat * (int) $row->quantity,
                'is_active' => (bool) $row->is_active,
            ]);
    }

    /**
     * Totals per shape: how many lots, how many stones, how much carat.
     *
     * @return Collection<int, array{shape: string, stone_count: int, quantity: int, total_carat: float}>
     */
    public function stockByShape(?int $branchId): Collection
    {
        return $this->stones($branchId)
            ->selectRaw("COALESCE(NULLIF(stones.shape, ''), 'Unspecified') as shape")
            ->selectRaw('COUNT(*) as stone_count')
            ->selectRaw('COALESCE(SUM(stones.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(stones.quantity * stones.carat), 0) as total_carat')
            ->groupByRaw("COALESCE(NULLIF(stones.shape, ''), 'Unspecified')")
            ->orderByRaw('COALESCE(SUM(stones.quantity), 0) DESC')
            ->get()
            ->map(fn ($row): array => [
                'shape' => (string) $row->shape,
                'stone_count' => $this->toInt($row->stone_count),
                'quantity' => $this->toInt($row->quantity),
                'total_carat' => $this->toFloat($row->total_carat),
            ]);
    }

    /**
     * Stones at zero quantity or at/below the configured low threshold.
     *
     * Inactive (deactivated) stones are excluded: a deactivated packet is not
     * something to reorder.
     *
     * @return Collection<int, array{uuid: string, stone_code: string, name: string, shape: string, carat: float, quantity: int, severity: string}>
     */
    public function zeroAndLowStock(?int $branchId): Collection
    {
        $threshold = (int) config('annigems.reports.low_stone_threshold', 5);

        return $this->stones($branchId)
            ->where('stones.is_active', true)
            ->where('stones.quantity', '<=', $threshold)
            ->orderBy('stones.quantity')
            ->orderBy('stones.name')
            ->get([
                'stones.uuid',
                'stones.stone_code',
                'stones.name',
                'stones.shape',
                'stones.carat',
                'stones.quantity',
            ])
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'stone_code' => (string) $row->stone_code,
                'name' => (string) $row->name,
                'shape' => (string) ($row->shape ?? '—'),
                'carat' => (float) $row->carat,
                'quantity' => (int) $row->quantity,
                // Zero is out of stock; anything else here is merely low.
                'severity' => (int) $row->quantity === 0 ? 'Out of stock' : 'Low stock',
            ]);
    }

    /**
     * Count of active stone types currently at zero quantity.
     */
    public function zeroStockCount(?int $branchId): int
    {
        return $this->stones($branchId)
            ->where('is_active', true)
            ->where('quantity', 0)
            ->count();
    }

    /**
     * Total number of individual stones held (sum of quantities).
     */
    public function totalStonesInStock(?int $branchId): int
    {
        return $this->toInt($this->stones($branchId)->sum('quantity'));
    }

    /**
     * Total carat held across all stone packets.
     */
    public function totalCaratInStock(?int $branchId): float
    {
        return $this->toFloat(
            $this->stones($branchId)->sum(\Illuminate\Support\Facades\DB::raw('stones.quantity * stones.carat'))
        );
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
            ->get(['uuid', 'intake_number', 'intake_date', 'total_quantity', 'total_carat'])
            ->map(fn (StoneIntake $intake): array => [
                'uuid' => (string) $intake->uuid,
                'intake_number' => (string) $intake->intake_number,
                'intake_date' => (string) $intake->intake_date?->toDateString(),
                'quantity' => (int) $intake->total_quantity,
                'total_carat' => (float) $intake->total_carat,
            ]);
    }

    /**
     * Consumption in the period, per stone, split by outward movement type.
     *
     * Only out-flows are counted: quantity_change is negative for consumption,
     * so its absolute value is the quantity consumed. Which stones move and
     * which sit still is the whole point of this report.
     *
     * @return Collection<int, array{stone_code: string, name: string, shape: string, used_in_item: int, sold: int, returned: int, other_out: int, total_out: int}>
     */
    public function consumption(ReportFilter $filter): Collection
    {
        $query = $this->movements($filter)
            ->join('stones', 'stones.id', '=', 'stone_stock_movements.stone_id')
            ->selectRaw("stones.stone_code, stones.name, COALESCE(NULLIF(stones.shape, ''), 'Unspecified') as shape")
            ->selectRaw('stone_stock_movements.type as movement_type')
            ->selectRaw('COALESCE(SUM(stone_stock_movements.quantity_change), 0) as net_change')
            ->groupByRaw("stones.stone_code, stones.name, COALESCE(NULLIF(stones.shape, ''), 'Unspecified'), stone_stock_movements.type");

        $byStone = [];

        foreach ($query->get() as $row) {
            $code = (string) $row->stone_code;

            $byStone[$code] ??= [
                'stone_code' => $code,
                'name' => (string) $row->name,
                'shape' => (string) $row->shape,
                'used_in_item' => 0,
                'sold' => 0,
                'returned' => 0,
                'other_out' => 0,
                'total_out' => 0,
            ];

            $change = $this->toInt($row->net_change);

            // Inward movements (intake, returns to stock) are not consumption.
            if ($change >= 0) {
                continue;
            }

            $consumed = abs($change);

            $bucket = match ($this->scalar($row->movement_type)) {
                StoneMovementType::USED_IN_ITEM->value => 'used_in_item',
                StoneMovementType::SOLD->value => 'sold',
                StoneMovementType::RETURNED->value => 'returned',
                default => 'other_out',
            };

            $byStone[$code][$bucket] += $consumed;
            $byStone[$code]['total_out'] += $consumed;
        }

        return collect($byStone)
            ->sortByDesc('total_out')
            ->values();
    }

    /**
     * Full movement ledger, newest first.
     *
     * quantity_before and quantity_after are returned on every row because they
     * are the reconciliation check: for one stone, each row's before must equal
     * the previous row's after. A break means the ledger was written outside
     * StoneStockService.
     *
     * @return Collection<int, array{
     *     stone_code: string, stone_name: string, type: string,
     *     quantity_change: int, quantity_before: int, quantity_after: int,
     *     notes: ?string, actor_name: ?string, actor_role: ?string,
     *     actor_status: ?string, actor_deleted_at: ?string, created_at: string
     * }>
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
                'notes' => $row->notes,
                'actor_name' => $row->actor_name,
                'actor_role' => $row->actor_role,
                'actor_status' => $row->actor_status,
                'actor_deleted_at' => $row->actor_deleted_at,
                'created_at' => (string) $row->created_at,
            ]);
    }

    /**
     * Movement volume per type, for the SuperAdmin audit aggregate.
     *
     * @return Collection<int, array{type: string, movements: int, quantity_in: int, quantity_out: int}>
     */
    public function movementVolumeByType(ReportFilter $filter): Collection
    {
        return $this->movements($filter)
            ->selectRaw('stone_stock_movements.type')
            ->selectRaw('COUNT(*) as movements')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END), 0) as quantity_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity_change < 0 THEN -quantity_change ELSE 0 END), 0) as quantity_out')
            ->groupBy('stone_stock_movements.type')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row): array => [
                'type' => $this->scalar($row->type),
                'movements' => $this->toInt($row->movements),
                'quantity_in' => $this->toInt($row->quantity_in),
                'quantity_out' => $this->toInt($row->quantity_out),
            ]);
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
