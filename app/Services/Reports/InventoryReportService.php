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
            title: 'Stock Summary',
            summary: [
                $this->card('total_pieces', 'Total Pieces', ReportFormatter::count($rows->sum('pieces')), 'inventory_2'),
                $this->card('available', 'Available', ReportFormatter::count($this->inventory->availablePieceCount($filter->branchId)), 'check_circle'),
                $this->card('gold_weight', 'Gold Weight in Stock', ReportFormatter::grams($this->inventory->goldWeightInStock($filter->branchId)), 'scale',
                    hint: 'Available pieces only'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'label', 'label' => 'Status'],
                ['key' => 'pieces', 'label' => 'Pieces', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'net_weight', 'label' => 'Net Weight', 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'label' => 'Total',
                'pieces' => $rows->sum('pieces'),
                'net_weight' => $rows->sum('net_weight'),
            ],
            chart: ReportResult::chart('Pieces by status', 'pieces', $rows->map(fn (array $row): array => [
                'label' => $row['label'],
                'value' => $row['pieces'],
                'display' => ReportFormatter::count($row['pieces']),
            ])->all()),
            meta: [
                'table_title' => 'Stock by status',
                'live_snapshot' => true,
                'empty_title' => 'No jewellery items yet',
                'empty_message' => 'Add your first piece to start tracking stock.',
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
            title: 'Gold Weight in Stock',
            summary: [
                $this->card('net_weight', 'Net Weight Held', ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('gross_weight', 'Gross Weight Held', ReportFormatter::grams($rows->sum('gross_weight')), 'monitor_weight',
                    hint: 'Includes stone weight'),
                $this->card('pieces', 'Available Pieces', ReportFormatter::count($rows->sum('pieces')), 'inventory_2'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'purity', 'label' => 'Metal & Purity'],
                ['key' => 'pieces', 'label' => 'Pieces', 'align' => 'right', 'format' => 'count'],
                ['key' => 'gross_weight', 'label' => 'Gross Weight', 'align' => 'right', 'format' => 'grams'],
                ['key' => 'net_weight', 'label' => 'Net Weight', 'align' => 'right', 'format' => 'grams', 'emphasis' => 'strong'],
            ],
            totals: [
                'purity' => 'Total',
                'pieces' => $rows->sum('pieces'),
                'gross_weight' => $rows->sum('gross_weight'),
                'net_weight' => $rows->sum('net_weight'),
            ],
            chart: ReportResult::chart('Net weight held by purity', 'grams', $rows->map(fn (array $row): array => [
                'label' => $row['purity'],
                'value' => $row['net_weight'],
                'display' => ReportFormatter::grams($row['net_weight']),
            ])->all()),
            meta: [
                'table_title' => 'Weight held by purity',
                'live_snapshot' => true,
                'empty_title' => 'No available stock',
                'empty_message' => 'Reserved, sold, damaged and lost pieces are excluded — this counts metal you can actually work with.',
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
            title: 'Category Stock',
            summary: [
                $this->card('pieces', 'Pieces', ReportFormatter::count($rows->sum('pieces')), 'inventory_2'),
                $this->card('net_weight', 'Net Weight', ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('groups', 'Category Groups', ReportFormatter::count($rows->count()), 'category'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'sub_category', 'label' => 'Sub-Category'],
                ['key' => 'pieces', 'label' => 'Pieces', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'net_weight', 'label' => 'Net Weight', 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'category' => 'Total',
                'pieces' => $rows->sum('pieces'),
                'net_weight' => $rows->sum('net_weight'),
            ],
            meta: [
                'table_title' => 'Stock by category',
                'live_snapshot' => true,
                'empty_title' => 'No stock in this category',
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
            title: 'Aging Stock',
            summary: [
                $this->card('pieces', 'Available Pieces', ReportFormatter::count($rows->sum('pieces')), 'inventory_2'),
                $this->card('overdue', 'Held Over '.$oldest.' Days', ReportFormatter::count($overdue['pieces'] ?? 0), 'hourglass_bottom',
                    hint: ReportFormatter::grams($overdue['net_weight'] ?? 0).' of metal idle'),
                $this->card('net_weight', 'Net Weight Held', ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'bucket', 'label' => 'Age'],
                ['key' => 'pieces', 'label' => 'Pieces', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'net_weight', 'label' => 'Net Weight', 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'bucket' => 'Total',
                'pieces' => $rows->sum('pieces'),
                'net_weight' => $rows->sum('net_weight'),
            ],
            chart: ReportResult::chart('Available pieces by age', 'pieces', $rows->map(fn (array $row): array => [
                'label' => $row['bucket'],
                'value' => $row['pieces'],
                'display' => ReportFormatter::count($row['pieces']),
            ])->all()),
            meta: [
                'table_title' => 'Age buckets',
                'live_snapshot' => true,
                'empty_title' => 'No available stock to age',
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
            title: 'Items With No Movement',
            summary: [
                $this->card('pieces', 'Untouched Pieces', ReportFormatter::count($rows->count()), 'do_not_touch'),
                $this->card('net_weight', 'Net Weight Idle', ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('oldest', 'Longest Held',
                    $rows->isNotEmpty() ? ReportFormatter::days($rows->max('days_held')) : '—', 'hourglass_bottom'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'item_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Item'],
                ['key' => 'created_at', 'label' => 'Added'],
                ['key' => 'days_held', 'label' => 'Days Held', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'net_weight', 'label' => 'Net Weight', 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'item_code' => 'Total',
                'net_weight' => $rows->sum('net_weight'),
            ],
            meta: [
                'table_title' => 'Untouched since creation',
                'live_snapshot' => true,
                'empty_title' => 'Every piece has moved at least once',
                'empty_message' => 'No available item is sitting with only its creation record.',
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
            title: 'Jewellery Movement Log',
            summary: [
                $this->card('movements', 'Movements Recorded', ReportFormatter::count($rows->count()), 'history'),
                $this->card('items', 'Pieces Touched', ReportFormatter::count($rows->pluck('item_code')->unique()->count()), 'inventory_2'),
                $this->card('actors', 'Staff Involved', ReportFormatter::count($rows->pluck('actor')->unique()->count()), 'group'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'created_at', 'label' => 'When', 'format' => 'datetime'],
                ['key' => 'item_code', 'label' => 'Code'],
                ['key' => 'item_name', 'label' => 'Item'],
                ['key' => 'type', 'label' => 'Movement'],
                ['key' => 'transition', 'label' => 'Status Change'],
                ['key' => 'actor', 'label' => 'By'],
                ['key' => 'notes', 'label' => 'Notes'],
            ],
            meta: [
                'table_title' => 'Movement history',
                'empty_title' => 'No movements in this period',
                'empty_message' => 'Nothing was created, reserved, sold or restatused in the selected range.',
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
