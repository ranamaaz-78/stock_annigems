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
            title: __('Stone Stock Summary'),
            summary: [
                $this->card('quantity', __('Stones in Stock'), ReportFormatter::count($rows->sum('quantity')), 'diamond'),
                $this->card('grams', __('Weight Held'), ReportFormatter::grams($rows->sum('grams')), 'scale'),
                $this->card('value', __('Stock Value'), ReportFormatter::money($rows->sum('value')), 'payments',
                    hint: __('At each lot\'s own rate')),
                $this->card('carat', __('Total Carat'), ReportFormatter::carats($rows->sum('total_carat')), 'diamond'),
                $this->card('zero', __('At Zero Stock'), ReportFormatter::count($rows->sum('zero_stock')), 'warning',
                    hint: __('Registered stones needing reorder')),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'quantity', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'grams', 'label' => __('Grams'), 'align' => 'right', 'format' => 'grams'],
                ['key' => 'total_carat', 'label' => __('Total Carat'), 'align' => 'right', 'format' => 'carats'],
                ['key' => 'value', 'label' => __('Value'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'zero_stock', 'label' => __('At Zero'), 'align' => 'right', 'format' => 'count'],
            ],
            totals: [
                'branch' => 'All Branches',
                'quantity' => $rows->sum('quantity'),
                'grams' => $rows->sum('grams'),
                'total_carat' => $rows->sum('total_carat'),
                'value' => $rows->sum('value'),
                'zero_stock' => $rows->sum('zero_stock'),
            ],
            chart: ReportResult::chart(__('Stones held by branch'), 'stones', $rows->map(fn (array $row): array => [
                'label' => $row['branch'],
                'value' => $row['quantity'],
                'display' => ReportFormatter::count($row['quantity']),
            ])->all()),
            meta: $this->meta([
                'table_title' => __('Stone stock by branch'),
                'live_snapshot' => true,
                'money' => true,
                'extra_tables' => [
                    [
                        'title' => __('Totals by shape'),
                        'columns' => [
                            ['key' => 'shape', 'label' => __('Shape')],
                            ['key' => 'stone_count', 'label' => __('Registered'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'quantity', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                            ['key' => 'grams', 'label' => __('Grams'), 'align' => 'right', 'format' => 'grams'],
                            ['key' => 'total_carat', 'label' => __('Total Carat'), 'align' => 'right', 'format' => 'carats'],
                            ['key' => 'value', 'label' => __('Value'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $byShape,
                        'totals' => [
                            'shape' => __('Total'),
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
            title: __('Zero & Low Stone Stock'),
            summary: [
                $this->card('zero', __('Out of Stock'), ReportFormatter::count($rows->sum('zero_stock')), 'error'),
                $this->card('low', __('Low Stock'), ReportFormatter::count($rows->sum('low_stock')), 'warning',
                    hint: __('At or below :value', ['value' => $threshold])),
                $this->card('flagged', __('Needing Attention'), ReportFormatter::count($rows->sum('flagged')), 'notification_important'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'zero_stock', 'label' => __('Out of Stock'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'low_stock', 'label' => __('Low Stock'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'flagged', 'label' => __('Total Flagged'), 'align' => 'right', 'format' => 'count'],
            ],
            totals: [
                'branch' => 'All Branches',
                'zero_stock' => $rows->sum('zero_stock'),
                'low_stock' => $rows->sum('low_stock'),
                'flagged' => $rows->sum('flagged'),
            ],
            meta: $this->meta([
                'table_title' => __('Reorder pressure by branch'),
                'live_snapshot' => true,
                'empty_title' => __('Every stone is above the reorder threshold'),
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
            title: __('Stone Intake Volume'),
            summary: [
                $this->card('batches', __('Intake Batches'), ReportFormatter::count($rows->sum('batches')), 'move_to_inbox'),
                $this->card('quantity', __('Stones Received'), ReportFormatter::count($rows->sum('quantity')), 'diamond',
                    hint: __(':grams by weight', ['grams' => ReportFormatter::grams($rows->sum('weight_grams'))])),
                $this->card('carat', __('Carat Received'), ReportFormatter::carats($rows->sum('total_carat')), 'scale'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'batches', 'label' => __('Batches'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'quantity', 'label' => __('Pieces Received'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'weight_grams', 'label' => __('Grams'), 'align' => 'right', 'format' => 'grams'],
                ['key' => 'total_carat', 'label' => __('Carat'), 'align' => 'right', 'format' => 'carats'],
            ],
            totals: [
                'branch' => 'All Branches',
                'batches' => $rows->sum('batches'),
                'quantity' => $rows->sum('quantity'),
                'weight_grams' => $rows->sum('weight_grams'),
                'total_carat' => $rows->sum('total_carat'),
            ],
            meta: $this->meta(['table_title' => __('Intake by branch'), 'empty_title' => __('No stone intakes in this period')]),
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
            title: __('Stone Consumption'),
            summary: [
                $this->card('total_out', __('Stones Consumed'), ReportFormatter::count($rows->sum('total_out')), 'trending_down',
                    hint: __(':grams by weight', ['grams' => ReportFormatter::grams($rows->sum('total_out_g'))])),
                $this->card('used_in_item', __('Set Into Pieces'), ReportFormatter::count($rows->sum('used_in_item')), 'diamond'),
                $this->card('sold', __('Sold Loose'), ReportFormatter::count($rows->sum('sold')), 'sell'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'used_in_item', 'label' => __('Set Into Pieces'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'sold', 'label' => __('Sold Loose'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'with_clients', 'label' => __('With Clients'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'written_off', 'label' => __('Written Off'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'back', 'label' => __('Back to Stock'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'muted'],
                ['key' => 'total_out', 'label' => __('Pieces Out'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'total_out_g', 'label' => __('Grams Out'), 'align' => 'right', 'format' => 'grams'],
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
                'table_title' => __('Consumption by branch'),
                'empty_title' => __('No stones were consumed in this period'),
            ]),
        );
    }
}
