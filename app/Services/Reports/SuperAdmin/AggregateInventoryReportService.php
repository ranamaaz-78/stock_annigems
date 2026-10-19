<?php

namespace App\Services\Reports\SuperAdmin;

use App\Repositories\Reports\InventoryReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * AggregateInventoryReportService
 *
 * SuperAdmin jewellery-stock reporting.
 *
 * Counts and weights per branch, per status, per category, per purity, per age
 * bucket — never a piece. The Admin "Items With No Movement" report lists
 * individual pieces and therefore has no counterpart here.
 *
 * As everywhere else in Module 6, there is no valuation: no purchase cost is
 * recorded, so stock worth cannot be computed and must not be approximated.
 */
class AggregateInventoryReportService extends AggregateReportService
{
    public function __construct(
        private InventoryReportRepository $inventory,
    ) {}

    /**
     * Stock levels per branch.
     */
    public function stockSummary(ReportFilter $filter): ReportResult
    {
        $rows = $this->perBranch($filter, fn (ReportFilter $scoped): array => [
            'total_pieces' => $this->inventory->totalPieceCount($scoped->branchId),
            'available_pieces' => $this->inventory->availablePieceCount($scoped->branchId),
            'gold_weight' => $this->inventory->goldWeightInStock($scoped->branchId),
        ]);

        $byStatus = $this->inventory->stockSummaryByStatus($filter->branchId);

        return new ReportResult(
            key: 'stock-summary',
            title: __('Stock Summary'),
            summary: [
                $this->card('total_pieces', __('Total Pieces'), ReportFormatter::count($rows->sum('total_pieces')), 'inventory_2'),
                $this->card('available', __('Available'), ReportFormatter::count($rows->sum('available_pieces')), 'check_circle'),
                $this->card('gold_weight', __('Gold Weight in Stock'), ReportFormatter::grams($rows->sum('gold_weight')), 'scale',
                    hint: __('Available pieces only')),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'total_pieces', 'label' => __('Total Pieces'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'available_pieces', 'label' => __('Available'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'gold_weight', 'label' => __('Gold in Stock'), 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'branch' => 'All Branches',
                'total_pieces' => $rows->sum('total_pieces'),
                'available_pieces' => $rows->sum('available_pieces'),
                'gold_weight' => $rows->sum('gold_weight'),
            ],
            chart: ReportResult::chart(__('Available pieces by branch'), 'pieces', $rows->map(fn (array $row): array => [
                'label' => $row['branch'],
                'value' => $row['available_pieces'],
                'display' => ReportFormatter::count($row['available_pieces']),
            ])->all()),
            meta: $this->meta([
                'table_title' => __('Stock by branch'),
                'live_snapshot' => true,
                'extra_tables' => [
                    [
                        'title' => __('Pieces by lifecycle status'),
                        'columns' => [
                            ['key' => 'label', 'label' => __('Status')],
                            ['key' => 'pieces', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                            ['key' => 'net_weight', 'label' => __('Net Weight'), 'align' => 'right', 'format' => 'grams'],
                        ],
                        'rows' => $byStatus,
                        'totals' => [
                            'label' => __('Total'),
                            'pieces' => $byStatus->sum('pieces'),
                            'net_weight' => $byStatus->sum('net_weight'),
                        ],
                    ],
                ],
            ]),
        );
    }

    /**
     * Metal held per purity across the selected scope.
     */
    public function goldWeightInStock(ReportFilter $filter): ReportResult
    {
        $rows = $this->inventory->goldWeightInStockByPurity($filter->branchId);

        return new ReportResult(
            key: 'gold-weight-in-stock',
            title: __('Gold Weight in Stock'),
            summary: [
                $this->card('net_weight', __('Net Weight Held'), ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('gross_weight', __('Gross Weight Held'), ReportFormatter::grams($rows->sum('gross_weight')), 'monitor_weight'),
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
            meta: $this->meta(['table_title' => __('Weight held by purity'), 'live_snapshot' => true]),
        );
    }

    /**
     * Stock spread across categories.
     */
    public function categoryStock(ReportFilter $filter): ReportResult
    {
        $rows = $this->inventory->categoryStock($filter->branchId);

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
            meta: $this->meta(['table_title' => __('Stock by category'), 'live_snapshot' => true]),
        );
    }

    /**
     * Age profile of available stock.
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
            meta: $this->meta(['table_title' => __('Age buckets'), 'live_snapshot' => true]),
        );
    }
}
