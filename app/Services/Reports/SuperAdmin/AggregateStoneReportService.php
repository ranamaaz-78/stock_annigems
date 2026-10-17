<?php

namespace App\Services\Reports\SuperAdmin;

use App\Repositories\Reports\StoneReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * AggregateStoneReportService
 *
 * SuperAdmin loose-stone reporting: quantities and carats, grouped by branch,
 * by stone type, or by movement type.
 *
 * The Admin movement ledger has no counterpart here. A ledger row names an
 * individual stone packet and the person who touched it, which is exactly the
 * record-level detail spec 7.4 keeps away from this actor.
 */
class AggregateStoneReportService extends AggregateReportService
{
    public function __construct(
        private StoneReportRepository $stones,
    ) {}

    /**
     * Stone holdings per branch, with a stone-type breakdown beneath.
     */
    public function stockSummary(ReportFilter $filter): ReportResult
    {
        $rows = $this->perBranch($filter, fn (ReportFilter $scoped): array => [
            'quantity' => $this->stones->totalStonesInStock($scoped->branchId),
            'grams' => $this->stones->totalStockGrams($scoped->branchId),
            'total_carat' => $this->stones->totalCaratInStock($scoped->branchId),
            'value' => $this->stones->stockValue($scoped->branchId),
            'zero_stock' => $this->stones->zeroStockCount($scoped->branchId),
        ]);

        $byShape = $this->stones->stockByShape($filter->branchId);

        return new ReportResult(
            key: 'stone-stock-summary',
            title: 'Stone Stock Summary',
            summary: [
                $this->card('quantity', 'Stones in Stock', ReportFormatter::count($rows->sum('quantity')), 'diamond'),
                $this->card('grams', 'Weight Held', ReportFormatter::grams($rows->sum('grams')), 'scale'),
                $this->card('value', 'Stock Value', ReportFormatter::money($rows->sum('value')), 'payments',
                    hint: 'At each lot\'s own rate'),
                $this->card('carat', 'Total Carat', ReportFormatter::carats($rows->sum('total_carat')), 'diamond'),
                $this->card('zero', 'At Zero Stock', ReportFormatter::count($rows->sum('zero_stock')), 'warning',
                    hint: 'Registered stones needing reorder'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'quantity', 'label' => 'Pieces', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'grams', 'label' => 'Grams', 'align' => 'right', 'format' => 'grams'],
                ['key' => 'total_carat', 'label' => 'Total Carat', 'align' => 'right', 'format' => 'carats'],
                ['key' => 'value', 'label' => 'Value', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'zero_stock', 'label' => 'At Zero', 'align' => 'right', 'format' => 'count'],
            ],
            totals: [
                'branch' => 'All Branches',
                'quantity' => $rows->sum('quantity'),
                'grams' => $rows->sum('grams'),
                'total_carat' => $rows->sum('total_carat'),
                'value' => $rows->sum('value'),
                'zero_stock' => $rows->sum('zero_stock'),
            ],
            chart: ReportResult::chart('Stones held by branch', 'stones', $rows->map(fn (array $row): array => [
                'label' => $row['branch'],
                'value' => $row['quantity'],
                'display' => ReportFormatter::count($row['quantity']),
            ])->all()),
            meta: $this->meta([
                'table_title' => 'Stone stock by branch',
                'live_snapshot' => true,
                'money' => true,
                'extra_tables' => [
                    [
                        'title' => 'Totals by shape',
                        'columns' => [
                            ['key' => 'shape', 'label' => 'Shape'],
                            ['key' => 'stone_count', 'label' => 'Registered', 'align' => 'right', 'format' => 'count'],
                            ['key' => 'quantity', 'label' => 'Pieces', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                            ['key' => 'grams', 'label' => 'Grams', 'align' => 'right', 'format' => 'grams'],
                            ['key' => 'total_carat', 'label' => 'Total Carat', 'align' => 'right', 'format' => 'carats'],
                            ['key' => 'value', 'label' => 'Value', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $byShape,
                        'totals' => [
                            'shape' => 'Total',
                            'stone_count' => $byShape->sum('stone_count'),
                            'quantity' => $byShape->sum('quantity'),
                            'grams' => $byShape->sum('grams'),
                            'total_carat' => $byShape->sum('total_carat'),
                            'value' => $byShape->sum('value'),
                        ],
                    ],
                ],
            ]),
        );
    }

    /**
     * Reorder pressure per branch.
     *
     * Counts only. The Admin version names each stone that needs reordering,
     * which is operational detail for the branch that must act on it.
     */
    public function lowStock(ReportFilter $filter): ReportResult
    {
        $threshold = (int) config('annigems.reports.low_stone_threshold', 5);

        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $flagged = $this->stones->zeroAndLowStock($scoped->branchId);
            $zero = $flagged->where('severity', 'Out of stock')->count();

            return [
                'zero_stock' => $zero,
                'low_stock' => $flagged->count() - $zero,
                'flagged' => $flagged->count(),
            ];
        });

        return new ReportResult(
            key: 'stone-low-stock',
            title: 'Zero & Low Stone Stock',
            summary: [
                $this->card('zero', 'Out of Stock', ReportFormatter::count($rows->sum('zero_stock')), 'error'),
                $this->card('low', 'Low Stock', ReportFormatter::count($rows->sum('low_stock')), 'warning',
                    hint: 'At or below '.$threshold),
                $this->card('flagged', 'Needing Attention', ReportFormatter::count($rows->sum('flagged')), 'notification_important'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'zero_stock', 'label' => 'Out of Stock', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'low_stock', 'label' => 'Low Stock', 'align' => 'right', 'format' => 'count'],
                ['key' => 'flagged', 'label' => 'Total Flagged', 'align' => 'right', 'format' => 'count'],
            ],
            totals: [
                'branch' => 'All Branches',
                'zero_stock' => $rows->sum('zero_stock'),
                'low_stock' => $rows->sum('low_stock'),
                'flagged' => $rows->sum('flagged'),
            ],
            meta: $this->meta([
                'table_title' => 'Reorder pressure by branch',
                'live_snapshot' => true,
                'empty_title' => 'Every stone is above the reorder threshold',
            ]),
        );
    }

    /**
     * Intake volume per branch.
     */
    public function intakeHistory(ReportFilter $filter): ReportResult
    {
        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $intakes = $this->stones->intakeHistory($scoped);

            return [
                'batches' => $intakes->count(),
                'quantity' => $intakes->sum('quantity'),
                'weight_grams' => $intakes->sum('weight_grams'),
                'total_carat' => $intakes->sum('total_carat'),
            ];
        });

        return new ReportResult(
            key: 'stone-intake-history',
            title: 'Stone Intake Volume',
            summary: [
                $this->card('batches', 'Intake Batches', ReportFormatter::count($rows->sum('batches')), 'move_to_inbox'),
                $this->card('quantity', 'Stones Received', ReportFormatter::count($rows->sum('quantity')), 'diamond',
                    hint: ReportFormatter::grams($rows->sum('weight_grams')).' by weight'),
                $this->card('carat', 'Carat Received', ReportFormatter::carats($rows->sum('total_carat')), 'scale'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'batches', 'label' => 'Batches', 'align' => 'right', 'format' => 'count'],
                ['key' => 'quantity', 'label' => 'Pieces Received', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'weight_grams', 'label' => 'Grams', 'align' => 'right', 'format' => 'grams'],
                ['key' => 'total_carat', 'label' => 'Carat', 'align' => 'right', 'format' => 'carats'],
            ],
            totals: [
                'branch' => 'All Branches',
                'batches' => $rows->sum('batches'),
                'quantity' => $rows->sum('quantity'),
                'weight_grams' => $rows->sum('weight_grams'),
                'total_carat' => $rows->sum('total_carat'),
            ],
            meta: $this->meta(['table_title' => 'Intake by branch', 'empty_title' => 'No stone intakes in this period']),
        );
    }

    /**
     * Where stones went, aggregated by movement type.
     */
    public function consumption(ReportFilter $filter): ReportResult
    {
        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $consumption = $this->stones->consumption($scoped);

            return [
                'used_in_item' => $consumption->sum('used_in_item'),
                'sold' => $consumption->sum('sold'),
                'with_clients' => $consumption->sum('delivered') + $consumption->sum('on_credit'),
                'written_off' => $consumption->sum('written_off'),
                'back' => $consumption->sum('back'),
                'total_out' => $consumption->sum('total_out'),
                'total_out_g' => round($consumption->sum('total_out_g'), 3),
            ];
        });

        return new ReportResult(
            key: 'stone-consumption',
            title: 'Stone Consumption',
            summary: [
                $this->card('total_out', 'Stones Consumed', ReportFormatter::count($rows->sum('total_out')), 'trending_down',
                    hint: ReportFormatter::grams($rows->sum('total_out_g')).' by weight'),
                $this->card('used_in_item', 'Set Into Pieces', ReportFormatter::count($rows->sum('used_in_item')), 'diamond'),
                $this->card('sold', 'Sold Loose', ReportFormatter::count($rows->sum('sold')), 'sell'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'used_in_item', 'label' => 'Set Into Pieces', 'align' => 'right', 'format' => 'count'],
                ['key' => 'sold', 'label' => 'Sold Loose', 'align' => 'right', 'format' => 'count'],
                ['key' => 'with_clients', 'label' => 'With Clients', 'align' => 'right', 'format' => 'count'],
                ['key' => 'written_off', 'label' => 'Written Off', 'align' => 'right', 'format' => 'count'],
                ['key' => 'back', 'label' => 'Back to Stock', 'align' => 'right', 'format' => 'count', 'emphasis' => 'muted'],
                ['key' => 'total_out', 'label' => 'Pieces Out', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'total_out_g', 'label' => 'Grams Out', 'align' => 'right', 'format' => 'grams'],
            ],
            totals: [
                'branch' => 'All Branches',
                'used_in_item' => $rows->sum('used_in_item'),
                'sold' => $rows->sum('sold'),
                'with_clients' => $rows->sum('with_clients'),
                'written_off' => $rows->sum('written_off'),
                'back' => $rows->sum('back'),
                'total_out' => $rows->sum('total_out'),
                'total_out_g' => $rows->sum('total_out_g'),
            ],
            meta: $this->meta([
                'table_title' => 'Consumption by branch',
                'empty_title' => 'No stones were consumed in this period',
            ]),
        );
    }
}
