<?php

namespace App\Services\Reports;

use App\Repositories\Reports\InventoryReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * InventoryReportService
 *
 * Builds the Admin jewellery-stock reports.
 *
 * Unlike the sales reports, these read live jewellery_items rows, and that is
 * correct: they answer "what is on the shelf right now", not "what was sold in
 * March". A date range therefore does not narrow most of them, which each
 * report states plainly rather than leaving the operator to guess.
 *
 * There is no valuation figure in this file and there must never be one. The
 * system records no purchase cost, so stock worth cannot be computed honestly.
 * Gold weight in stock is the truthful equivalent and the number a jeweller
 * actually asks for.
 */
class InventoryReportService
{
    public function __construct(
        private InventoryReportRepository $inventory,
    ) {}

    /**
     * Piece count and net weight for every lifecycle status.
     */
    public function stockSummary(ReportFilter $filter): ReportResult
    {
        $rows = $this->inventory->stockSummaryByStatus($filter->branchId);

        return new ReportResult(
            key: 'stock-summary',
            title: __('Stock Summary'),
            summary: [
                $this->card('total_pieces', __('Total Pieces'), ReportFormatter::count($rows->sum('pieces')), 'inventory_2'),
                $this->card('available', __('Available'), ReportFormatter::count($this->inventory->availablePieceCount($filter->branchId)), 'check_circle'),
                $this->card('gold_weight', __('Gold Weight in Stock'), ReportFormatter::grams($this->inventory->goldWeightInStock($filter->branchId)), 'scale',
                    hint: __('Available pieces only')),
            ],
            rows: $rows,
            columns: [
                ['key' => 'label', 'label' => __('Status')],
                ['key' => 'pieces', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'net_weight', 'label' => __('Net Weight'), 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'label' => __('Total'),
                'pieces' => $rows->sum('pieces'),
                'net_weight' => $rows->sum('net_weight'),
            ],
            chart: ReportResult::chart(__('Pieces by status'), 'pieces', $rows->map(fn (array $row): array => [
                'label' => $row['label'],
                'value' => $row['pieces'],
                'display' => ReportFormatter::count($row['pieces']),
            ])->all()),
            meta: [
                'table_title' => __('Stock by status'),
                'live_snapshot' => true,
                'empty_title' => __('No jewellery items yet'),
                'empty_message' => __('Add your first piece to start tracking stock.'),
            ],
        );
    }

    /**
     * Metal held per purity, for available pieces only.
     */
    public function goldWeightInStock(ReportFilter $filter): ReportResult
    {
        $rows = $this->inventory->goldWeightInStockByPurity($filter->branchId);

        return new ReportResult(
            key: 'gold-weight-in-stock',
            title: __('Gold Weight in Stock'),
            summary: [
                $this->card('net_weight', __('Net Weight Held'), ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('gross_weight', __('Gross Weight Held'), ReportFormatter::grams($rows->sum('gross_weight')), 'monitor_weight',
                    hint: __('Includes stone weight')),
                $this->card('pieces', __('Available Pieces'), ReportFormatter::count($rows->sum('pieces')), 'inventory_2'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'purity', 'label' => __('Metal & Purity')],
                ['key' => 'pieces', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'gross_weight', 'label' => __('Gross Weight'), 'align' => 'right', 'format' => 'grams'],
                ['key' => 'net_weight', 'label' => __('Net Weight'), 'align' => 'right', 'format' => 'grams', 'emphasis' => 'strong'],
            ],
            totals: [
                'purity' => __('Total'),
                'pieces' => $rows->sum('pieces'),
                'gross_weight' => $rows->sum('gross_weight'),
                'net_weight' => $rows->sum('net_weight'),
            ],
            chart: ReportResult::chart(__('Net weight held by purity'), 'grams', $rows->map(fn (array $row): array => [
                'label' => $row['purity'],
                'value' => $row['net_weight'],
                'display' => ReportFormatter::grams($row['net_weight']),
            ])->all()),
            meta: [
                'table_title' => __('Weight held by purity'),
                'live_snapshot' => true,
                'empty_title' => __('No available stock'),
                'empty_message' => __('Reserved, sold, damaged and lost pieces are excluded — this counts metal you can actually work with.'),
            ],
        );
    }

    /**
     * Piece count and weight per category and sub-category.
     */
    public function categoryStock(ReportFilter $filter): ReportResult
    {
        $rows = $this->inventory->categoryStock(
            $filter->branchId,
            $filter->hasOption('category_id') ? (int) $filter->option('category_id') : null
        );

        return new ReportResult(
            key: 'category-stock',
            title: __('Category Stock'),
            summary: [
                $this->card('pieces', __('Pieces'), ReportFormatter::count($rows->sum('pieces')), 'inventory_2'),
                $this->card('net_weight', __('Net Weight'), ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('groups', __('Category Groups'), ReportFormatter::count($rows->count()), 'category'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'category', 'label' => __('Category')],
                ['key' => 'sub_category', 'label' => __('Sub-Category')],
                ['key' => 'pieces', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'net_weight', 'label' => __('Net Weight'), 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'category' => __('Total'),
                'pieces' => $rows->sum('pieces'),
                'net_weight' => $rows->sum('net_weight'),
            ],
            meta: [
                'table_title' => __('Stock by category'),
                'live_snapshot' => true,
                'empty_title' => __('No stock in this category'),
            ],
        );
    }

    /**
     * How long available stock has been held, in configurable buckets.
     *
     * With no cost data this is the closest honest signal to "which stock is
     * costing us": it reports idle time, not idle money.
     */
    public function agingStock(ReportFilter $filter): ReportResult
    {
        $rows = $this->inventory->agingBuckets($filter->branchId);
        $edges = (array) config('annigems.reports.aging_buckets', [30, 60, 90, 180]);
        $oldest = $edges === [] ? 180 : (int) max($edges);

        $overdue = $rows->last();

        return new ReportResult(
            key: 'aging-stock',
            title: __('Aging Stock'),
            summary: [
                $this->card('pieces', __('Available Pieces'), ReportFormatter::count($rows->sum('pieces')), 'inventory_2'),
                $this->card('overdue', __('Held Over ').$oldest.' Days', ReportFormatter::count($overdue['pieces'] ?? 0), 'hourglass_bottom',
                    hint: __(':grams of metal idle', ['grams' => ReportFormatter::grams($overdue['net_weight'] ?? 0)])),
                $this->card('net_weight', __('Net Weight Held'), ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'bucket', 'label' => __('Age')],
                ['key' => 'pieces', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'net_weight', 'label' => __('Net Weight'), 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'bucket' => __('Total'),
                'pieces' => $rows->sum('pieces'),
                'net_weight' => $rows->sum('net_weight'),
            ],
            chart: ReportResult::chart(__('Available pieces by age'), 'pieces', $rows->map(fn (array $row): array => [
                'label' => $row['bucket'],
                'value' => $row['pieces'],
                'display' => ReportFormatter::count($row['pieces']),
            ])->all()),
            meta: [
                'table_title' => __('Age buckets'),
                'live_snapshot' => true,
                'empty_title' => __('No available stock to age'),
            ],
        );
    }

    /**
     * Pieces whose only movement is the initial CREATED record.
     *
     * A stronger signal than age alone: these have never even been reserved,
     * restatused or edited since they were entered.
     */
    public function noMovementItems(ReportFilter $filter): ReportResult
    {
        $rows = $this->inventory->itemsWithNoMovement($filter->branchId, (int) config('annigems.reports.per_page', 100));

        return new ReportResult(
            key: 'no-movement-items',
            title: __('Items With No Movement'),
            summary: [
                $this->card('pieces', __('Untouched Pieces'), ReportFormatter::count($rows->count()), 'do_not_touch'),
                $this->card('net_weight', __('Net Weight Idle'), ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('oldest', __('Longest Held'),
                    $rows->isNotEmpty() ? ReportFormatter::days($rows->max('days_held')) : '—', 'hourglass_bottom'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'item_code', 'label' => __('Code')],
                ['key' => 'name', 'label' => __('Item')],
                ['key' => 'created_at', 'label' => __('Added')],
                ['key' => 'days_held', 'label' => __('Days Held'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'net_weight', 'label' => __('Net Weight'), 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'item_code' => __('Total'),
                'net_weight' => $rows->sum('net_weight'),
            ],
            meta: [
                'table_title' => __('Untouched since creation'),
                'live_snapshot' => true,
                'empty_title' => __('Every piece has moved at least once'),
                'empty_message' => __('No available item is sitting with only its creation record.'),
            ],
        );
    }

    /**
     * Chain of custody for jewellery pieces (the Audit family's item log).
     */
    public function movementLog(ReportFilter $filter): ReportResult
    {
        $rows = $this->inventory->movementLog($filter, (int) config('annigems.reports.per_page', 100))
            ->map(fn (array $row): array => [
                'created_at' => $row['created_at'],
                'item_code' => $row['item_code'],
                'item_name' => $row['item_name'],
                'type' => $row['type'],
                'transition' => $this->transition($row['from_status'], $row['to_status']),
                'actor' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
                'notes' => $row['notes'],
            ]);

        return new ReportResult(
            key: 'item-movement-log',
            title: __('Jewellery Movement Log'),
            summary: [
                $this->card('movements', __('Movements Recorded'), ReportFormatter::count($rows->count()), 'history'),
                $this->card('items', __('Pieces Touched'), ReportFormatter::count($rows->pluck('item_code')->unique()->count()), 'inventory_2'),
                $this->card('actors', __('Staff Involved'), ReportFormatter::count($rows->pluck('actor')->unique()->count()), 'group'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'created_at', 'label' => __('When'), 'format' => 'datetime'],
                ['key' => 'item_code', 'label' => __('Code')],
                ['key' => 'item_name', 'label' => __('Item')],
                ['key' => 'type', 'label' => __('Movement')],
                ['key' => 'transition', 'label' => __('Status Change')],
                ['key' => 'actor', 'label' => __('By')],
                ['key' => 'notes', 'label' => __('Notes')],
            ],
            meta: [
                'table_title' => __('Movement history'),
                'empty_title' => __('No movements in this period'),
                'empty_message' => __('Nothing was created, reserved, sold or restatused in the selected range.'),
            ],
        );
    }

    /**
     * Render a status transition as "Available → Reserved".
     */
    private function transition(?string $from, ?string $to): string
    {
        if ($from === null && $to === null) {
            return '—';
        }

        return ($from ?? '—').' → '.($to ?? '—');
    }

    /**
     * A KPI card in the shape the shared shell expects.
     *
     * @param  ?array{direction: string, label: string}  $trend
     * @return array<string, mixed>
     */
    private function card(string $key, string $label, string $value, ?string $icon = null, ?string $hint = null, ?array $trend = null): array
    {
        return compact('key', 'label', 'value', 'icon', 'hint', 'trend');
    }
}
