<?php

namespace App\Repositories\Reports;

use App\Enums\JewelleryItemMovementType;
use App\Enums\JewelleryItemStatus;
use App\Models\JewelleryItem;
use App\Models\JewelleryItemMovement;
use App\Support\Reports\ReportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * InventoryReportRepository
 *
 * Jewellery stock reporting. Unlike sales reporting, these queries read **live**
 * jewellery_items rows, and that is correct: they describe what is on the shelf
 * right now, not what something looked like when it was sold.
 *
 * There is no valuation figure anywhere in this class. The system records no
 * purchase cost, so stock worth cannot be computed and must not be faked. Gold
 * weight in stock is the honest equivalent, and is the figure a jeweller
 * actually asks for.
 */
class InventoryReportRepository extends ReportRepository
{
    /**
     * Piece count per lifecycle status, including statuses with zero pieces.
     *
     * Zero rows are included deliberately: "Damaged: 0" is information, a
     * missing Damaged row is ambiguous.
     *
     * @return Collection<int, array{status: string, label: string, pieces: int, net_weight: float}>
     */
    public function stockSummaryByStatus(?int $branchId): Collection
    {
        $counts = $this->items($branchId)
            ->selectRaw('status')
            ->selectRaw('COUNT(*) as pieces')
            ->selectRaw('COALESCE(SUM(net_weight), 0) as net_weight')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row): string => $this->scalar($row->status));

        return collect(JewelleryItemStatus::cases())
            ->map(function (JewelleryItemStatus $status) use ($counts): array {
                $row = $counts->get($status->value);

                return [
                    'status' => $status->value,
                    'label' => $status->label(),
                    'pieces' => $this->toInt($row?->pieces),
                    'net_weight' => $this->toFloat($row?->net_weight),
                ];
            })
            ->values();
    }

    /**
     * Gross and net grams held per purity, for available pieces only.
     *
     * Restricted to `available` because reserved, sold, damaged and lost pieces
     * are not stock a jeweller can work with — including them would overstate
     * the metal actually in hand.
     *
     * @return Collection<int, array{purity: string, pieces: int, gross_weight: float, net_weight: float}>
     */
    public function goldWeightInStockByPurity(?int $branchId): Collection
    {
        return $this->items($branchId)
            ->where('jewellery_items.status', JewelleryItemStatus::AVAILABLE->value)
            ->join('purities', 'purities.id', '=', 'jewellery_items.purity_id')
            ->join('metal_types', 'metal_types.id', '=', 'purities.metal_type_id')
            // Metal and purity are selected separately and joined in PHP: SQL
            // string concatenation is not portable between MySQL and the SQLite
            // used by the test suite, and the reports must run identically on both.
            ->selectRaw('metal_types.name as metal_name')
            ->selectRaw('purities.name as purity_name')
            ->selectRaw('COUNT(*) as pieces')
            ->selectRaw('COALESCE(SUM(jewellery_items.gross_weight), 0) as gross_weight')
            ->selectRaw('COALESCE(SUM(jewellery_items.net_weight), 0) as net_weight')
            ->groupBy('metal_types.name', 'purities.name')
            ->orderByRaw('COALESCE(SUM(jewellery_items.net_weight), 0) DESC')
            ->get()
            ->map(fn ($row): array => [
                'purity' => trim($row->metal_name.' '.$row->purity_name),
                'pieces' => $this->toInt($row->pieces),
                'gross_weight' => $this->toFloat($row->gross_weight),
                'net_weight' => $this->toFloat($row->net_weight),
            ]);
    }

    /**
     * Total net grams of metal available in stock.
     */
    public function goldWeightInStock(?int $branchId): float
    {
        return $this->toFloat(
            $this->items($branchId)
                ->where('status', JewelleryItemStatus::AVAILABLE->value)
                ->sum('net_weight')
        );
    }

    /**
     * Count of pieces currently available for sale.
     */
    public function availablePieceCount(?int $branchId): int
    {
        return $this->items($branchId)
            ->where('status', JewelleryItemStatus::AVAILABLE->value)
            ->count();
    }

    /**
     * Total piece count regardless of status.
     */
    public function totalPieceCount(?int $branchId): int
    {
        return $this->items($branchId)->count();
    }

    /**
     * Piece count and net weight per category and sub-category.
     *
     * @return Collection<int, array{category: string, sub_category: string, pieces: int, net_weight: float}>
     */
    public function categoryStock(?int $branchId, ?int $categoryId = null): Collection
    {
        $query = $this->items($branchId)
            ->join('categories', 'categories.id', '=', 'jewellery_items.category_id')
            ->leftJoin('sub_categories', 'sub_categories.id', '=', 'jewellery_items.sub_category_id')
            ->selectRaw('categories.name as category')
            ->selectRaw("COALESCE(sub_categories.name, '—') as sub_category")
            ->selectRaw('COUNT(*) as pieces')
            ->selectRaw('COALESCE(SUM(jewellery_items.net_weight), 0) as net_weight')
            ->groupBy('categories.name', 'sub_categories.name')
            ->orderBy('categories.name')
            ->orderByRaw('COUNT(*) DESC');

        if ($categoryId !== null) {
            $query->where('jewellery_items.category_id', $categoryId);
        }

        return $query->get()->map(fn ($row): array => [
            'category' => (string) $row->category,
            'sub_category' => (string) $row->sub_category,
            'pieces' => $this->toInt($row->pieces),
            'net_weight' => $this->toFloat($row->net_weight),
        ]);
    }

    /**
     * Available pieces grouped into configurable age buckets.
     *
     * Bucket edges come from config('annigems.reports.aging_buckets') so a
     * business rule change never requires editing this class. Age is measured
     * from the item's creation date, since that is when capital started sitting
     * in the piece.
     *
     * With no cost data this is the closest thing to a profitability signal the
     * system can honestly produce — it says how long stock has been idle, not
     * what it is worth.
     *
     * @return Collection<int, array{bucket: string, pieces: int, net_weight: float}>
     */
    public function agingBuckets(?int $branchId): Collection
    {
        /** @var list<int> $edges */
        $edges = config('annigems.reports.aging_buckets', [30, 60, 90, 180]);
        sort($edges);

        $now = CarbonImmutable::now(setting('timezone', config('annigems.timezone')));

        $buckets = [];
        $previous = 0;

        foreach ($edges as $edge) {
            $buckets[] = [
                'label' => __(':from–:to days', ['from' => $previous === 0 ? 0 : $previous + 1, 'to' => $edge]),
                // Newer than $edge days: created after (now - edge).
                'from' => $now->subDays($edge)->startOfDay(),
                'to' => $now->subDays($previous)->endOfDay(),
            ];
            $previous = $edge;
        }

        // Final open-ended bucket for everything older than the last edge.
        $buckets[] = [
            'label' => __('Over :days days', ['days' => $previous]),
            'from' => null,
            'to' => $now->subDays($previous)->endOfDay(),
        ];

        return collect($buckets)->map(function (array $bucket) use ($branchId): array {
            $query = $this->items($branchId)
                ->where('status', JewelleryItemStatus::AVAILABLE->value)
                ->where('created_at', '<=', $bucket['to']->toDateTimeString());

            if ($bucket['from'] !== null) {
                $query->where('created_at', '>=', $bucket['from']->toDateTimeString());
            }

            $row = $query
                ->selectRaw('COUNT(*) as pieces')
                ->selectRaw('COALESCE(SUM(net_weight), 0) as net_weight')
                ->first();

            return [
                'bucket' => $bucket['label'],
                'pieces' => $this->toInt($row?->pieces),
                'net_weight' => $this->toFloat($row?->net_weight),
            ];
        });
    }

    /**
     * Individual available pieces ordered oldest first, with days held.
     *
     * @return Collection<int, array{uuid: string, item_code: string, name: string, category: string, purity: string, net_weight: float, days_held: int, created_at: string}>
     */
    public function agingItems(?int $branchId, int $limit, int $minimumDays = 0): Collection
    {
        $cutoff = CarbonImmutable::now(config('annigems.timezone'))
            ->subDays($minimumDays)
            ->endOfDay();

        return $this->items($branchId)
            ->where('jewellery_items.status', JewelleryItemStatus::AVAILABLE->value)
            ->where('jewellery_items.created_at', '<=', $cutoff->toDateTimeString())
            ->join('categories', 'categories.id', '=', 'jewellery_items.category_id')
            ->join('purities', 'purities.id', '=', 'jewellery_items.purity_id')
            ->orderBy('jewellery_items.created_at')
            ->limit($limit)
            ->get([
                'jewellery_items.uuid',
                'jewellery_items.item_code',
                'jewellery_items.name',
                'jewellery_items.net_weight',
                'jewellery_items.created_at',
                'categories.name as category_name',
                'purities.name as purity_name',
            ])
            ->map(function ($row): array {
                $createdAt = CarbonImmutable::parse($row->created_at);

                return [
                    'uuid' => (string) $row->uuid,
                    'item_code' => (string) $row->item_code,
                    'name' => (string) $row->name,
                    'category' => (string) $row->category_name,
                    'purity' => (string) $row->purity_name,
                    'net_weight' => (float) $row->net_weight,
                    'days_held' => (int) $createdAt->diffInDays(CarbonImmutable::now()),
                    'created_at' => $createdAt->toDateString(),
                ];
            });
    }

    /**
     * Pieces whose only movement record is the initial CREATED row.
     *
     * A piece that has never been reserved, repaired, restatused or edited has
     * genuinely sat untouched, which is a stronger signal than age alone.
     *
     * @return Collection<int, array{uuid: string, item_code: string, name: string, net_weight: float, days_held: int, created_at: string}>
     */
    public function itemsWithNoMovement(?int $branchId, int $limit): Collection
    {
        // Sub-select rather than a join so the outer query still counts pieces,
        // not movement rows. Branch scoping is unnecessary on the sub-select
        // because it is constrained by the outer query's item ids.
        $movedItemIds = $this->unscoped(JewelleryItemMovement::class)
            ->select('jewellery_item_id')
            ->where('type', '!=', JewelleryItemMovementType::CREATED->value);

        return $this->items($branchId)
            ->where('status', JewelleryItemStatus::AVAILABLE->value)
            ->whereNotIn('jewellery_items.id', $movedItemIds)
            ->orderBy('jewellery_items.created_at')
            ->limit($limit)
            ->get(['uuid', 'item_code', 'name', 'net_weight', 'created_at'])
            ->map(fn (JewelleryItem $item): array => [
                'uuid' => (string) $item->uuid,
                'item_code' => (string) $item->item_code,
                'name' => (string) $item->name,
                'net_weight' => (float) $item->net_weight,
                'days_held' => (int) $item->created_at->diffInDays(now()),
                'created_at' => $item->created_at->toDateString(),
            ]);
    }

    /**
     * Branch-wide jewellery movement log for the audit reports.
     *
     * @return Collection<int, array{
     *     item_code: string, item_uuid: string, item_name: string, type: string,
     *     from_status: ?string, to_status: ?string, notes: ?string,
     *     actor_name: ?string, actor_role: ?string, actor_status: ?string,
     *     actor_deleted_at: ?string, created_at: string
     * }>
     */
    public function movementLog(ReportFilter $filter, int $limit): Collection
    {
        $query = $this->unscoped(JewelleryItemMovement::class)
            ->join('jewellery_items', 'jewellery_items.id', '=', 'jewellery_item_movements.jewellery_item_id')
            ->leftJoin('users', 'users.id', '=', 'jewellery_item_movements.performed_by');

        $this->scopeToBranch($query, $filter->branchId, 'jewellery_item_movements');
        $this->scopeToRange($query, $filter, 'jewellery_item_movements.created_at');

        // Spec 5.6: filterable by item, type, user and date.
        if ($filter->hasOption('jewellery_item_id')) {
            $query->where('jewellery_item_movements.jewellery_item_id', $filter->option('jewellery_item_id'));
        }

        if ($filter->hasOption('movement_type')) {
            $query->where('jewellery_item_movements.type', $filter->option('movement_type'));
        }

        if ($filter->hasOption('staff_id')) {
            $query->where('jewellery_item_movements.performed_by', $filter->option('staff_id'));
        }

        return $query
            ->orderByDesc('jewellery_item_movements.created_at')
            ->orderByDesc('jewellery_item_movements.id')
            ->limit($limit)
            ->get([
                'jewellery_items.item_code',
                'jewellery_items.uuid as item_uuid',
                'jewellery_items.name as item_name',
                'jewellery_item_movements.type',
                'jewellery_item_movements.from_status',
                'jewellery_item_movements.to_status',
                'jewellery_item_movements.notes',
                'jewellery_item_movements.performed_by_role as actor_role',
                'jewellery_item_movements.created_at',
                'users.name as actor_name',
                'users.status as actor_status',
                'users.deleted_at as actor_deleted_at',
            ])
            ->map(fn ($row): array => [
                'item_code' => (string) $row->item_code,
                'item_uuid' => (string) $row->item_uuid,
                'item_name' => (string) $row->item_name,
                'type' => $this->scalar($row->type),
                'from_status' => $this->scalar($row->from_status),
                'to_status' => $this->scalar($row->to_status),
                'notes' => $row->notes,
                'actor_name' => $row->actor_name,
                'actor_role' => $row->actor_role,
                'actor_status' => $row->actor_status,
                'actor_deleted_at' => $row->actor_deleted_at,
                'created_at' => (string) $row->created_at,
            ]);
    }

    /**
     * Base live-item query, branch-scoped explicitly.
     */
    private function items(?int $branchId): Builder
    {
        $query = $this->unscoped(JewelleryItem::class);

        $this->scopeToBranch($query, $branchId, 'jewellery_items');

        return $query;
    }
}
