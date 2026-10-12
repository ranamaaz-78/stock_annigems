<?php

namespace App\Services\Reports;

use App\Enums\StoneMovementType;
use App\Repositories\Reports\StoneReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * StoneReportService
 *
 * Builds the Admin loose-stone reports.
 *
 * Stones are the one fungible inventory in the system — one row per stone type
 * carrying a quantity — so these reports count and weigh rather than enumerate
 * individual pieces. Stock figures read live records, because they describe
 * what is in the safe right now.
 *
 * No stone valuation appears anywhere: no stone purchase price is captured, so
 * none can be reported.
 */
class StoneReportService
{
    public function __construct(
        private StoneReportRepository $stones,
    ) {}

    /**
     * Quantity and carat in hand for every registered stone.
     */
    public function stockSummary(ReportFilter $filter): ReportResult
    {
        $rows = $this->stones->stockSummary($filter->branchId);
        $byShape = $this->stones->stockByShape($filter->branchId);

        return new ReportResult(
            key: 'stone-stock-summary',
            title: 'Stone Stock Summary',
            summary: [
                $this->card('stones', 'Stones in Stock', ReportFormatter::count($rows->sum('quantity')), 'diamond'),
                $this->card('carat', 'Total Carat', ReportFormatter::carats($rows->sum('total_carat')), 'scale'),
                $this->card('types', 'Registered Lots', ReportFormatter::count($rows->count()), 'category',
                    hint: ReportFormatter::count($byShape->count()).' shapes'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'stone_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Stone'],
                ['key' => 'shape', 'label' => 'Shape'],
                ['key' => 'carat', 'label' => 'Carat Each', 'align' => 'right', 'format' => 'carats'],
                ['key' => 'quantity', 'label' => 'Quantity', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'total_carat', 'label' => 'Total Carat', 'align' => 'right', 'format' => 'carats'],
            ],
            totals: [
                'stone_code' => 'Total',
                'quantity' => $rows->sum('quantity'),
                'total_carat' => $rows->sum('total_carat'),
            ],
            chart: ReportResult::chart('Quantity held by shape', 'stones', $byShape->map(fn (array $row): array => [
                'label' => $row['shape'],
                'value' => $row['quantity'],
                'display' => ReportFormatter::count($row['quantity']),
            ])->all()),
            meta: [
                'table_title' => 'Stones in stock',
                'live_snapshot' => true,
                'empty_title' => 'No stones registered yet',
                'empty_message' => 'Register a stone and record an intake to start tracking stone stock.',
                'extra_tables' => [
                    [
                        'title' => 'Totals by shape',
                        'columns' => [
                            ['key' => 'shape', 'label' => 'Shape'],
                            ['key' => 'stone_count', 'label' => 'Registered', 'align' => 'right', 'format' => 'count'],
                            ['key' => 'quantity', 'label' => 'Quantity', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                            ['key' => 'total_carat', 'label' => 'Total Carat', 'align' => 'right', 'format' => 'carats'],
                        ],
                        'rows' => $byShape,
                        'totals' => [
                            'shape' => 'Total',
                            'stone_count' => $byShape->sum('stone_count'),
                            'quantity' => $byShape->sum('quantity'),
                            'total_carat' => $byShape->sum('total_carat'),
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Stones out of stock or at/below the configured reorder threshold.
     */
    public function lowStock(ReportFilter $filter): ReportResult
    {
        $rows = $this->stones->zeroAndLowStock($filter->branchId);
        $threshold = (int) config('annigems.reports.low_stone_threshold', 5);
        $zero = $rows->where('quantity', 0)->count();

        return new ReportResult(
            key: 'stone-low-stock',
            title: 'Zero & Low Stone Stock',
            summary: [
                $this->card('zero', 'Out of Stock', ReportFormatter::count($zero), 'error'),
                $this->card('low', 'Low Stock', ReportFormatter::count($rows->count() - $zero), 'warning',
                    hint: 'At or below '.$threshold),
                $this->card('carat', 'Carat Remaining', ReportFormatter::carats($rows->sum(fn (array $row): float => $row['carat'] * $row['quantity'])), 'scale'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'stone_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Stone'],
                ['key' => 'shape', 'label' => 'Shape'],
                ['key' => 'carat', 'label' => 'Carat Each', 'align' => 'right', 'format' => 'carats'],
                ['key' => 'quantity', 'label' => 'Quantity', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'severity', 'label' => 'Status'],
            ],
            meta: [
                'table_title' => 'Needs reordering',
                'live_snapshot' => true,
                'empty_title' => 'Every stone is above the reorder threshold',
                'empty_message' => 'Nothing is at or below '.$threshold.' in stock.',
            ],
        );
    }

    /**
     * Intake batches received in the period.
     */
    public function intakeHistory(ReportFilter $filter): ReportResult
    {
        $rows = $this->stones->intakeHistory($filter)->map(fn (array $row): array => [
            'intake_number' => $row['intake_number'],
            'intake_date' => $row['intake_date'],
            'stone_types' => $row['stone_types'],
            'quantity' => $row['quantity'],
            'total_carat' => $row['total_carat'],
            'received_by' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
        ]);

        return new ReportResult(
            key: 'stone-intake-history',
            title: 'Stone Intake History',
            summary: [
                $this->card('batches', 'Intake Batches', ReportFormatter::count($rows->count()), 'move_to_inbox'),
                $this->card('quantity', 'Stones Received', ReportFormatter::count($rows->sum('quantity')), 'diamond'),
                $this->card('carat', 'Carat Received', ReportFormatter::carats($rows->sum('total_carat')), 'scale'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'intake_number', 'label' => 'Batch'],
                ['key' => 'intake_date', 'label' => 'Date'],
                ['key' => 'stone_types', 'label' => 'Lines', 'align' => 'right', 'format' => 'count'],
                ['key' => 'quantity', 'label' => 'Quantity', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'total_carat', 'label' => 'Carat', 'align' => 'right', 'format' => 'carats'],
                ['key' => 'received_by', 'label' => 'Received By'],
            ],
            totals: [
                'intake_number' => 'Total',
                'stone_types' => $rows->sum('stone_types'),
                'quantity' => $rows->sum('quantity'),
                'total_carat' => $rows->sum('total_carat'),
            ],
            meta: [
                'table_title' => 'Intake batches',
                'empty_title' => 'No stone intakes in this period',
            ],
        );
    }

    /**
     * Which stones actually move, split by where they went.
     *
     * Only out-flows count. A stone with a large registered quantity and zero
     * consumption is dead capital, which is precisely what this exposes.
     */
    public function consumption(ReportFilter $filter): ReportResult
    {
        $rows = $this->stones->consumption($filter);

        return new ReportResult(
            key: 'stone-consumption',
            title: 'Stone Consumption',
            summary: [
                $this->card('total_out', 'Stones Consumed', ReportFormatter::count($rows->sum('total_out')), 'trending_down'),
                $this->card('used_in_item', 'Set Into Pieces', ReportFormatter::count($rows->sum('used_in_item')), 'diamond'),
                $this->card('sold', 'Sold Loose', ReportFormatter::count($rows->sum('sold')), 'sell'),
                $this->card('returned', 'Returned', ReportFormatter::count($rows->sum('returned')), 'undo'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'stone_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Stone'],
                ['key' => 'shape', 'label' => 'Shape'],
                ['key' => 'used_in_item', 'label' => 'Set Into Pieces', 'align' => 'right', 'format' => 'count'],
                ['key' => 'sold', 'label' => 'Sold Loose', 'align' => 'right', 'format' => 'count'],
                ['key' => 'returned', 'label' => 'Returned', 'align' => 'right', 'format' => 'count'],
                ['key' => 'total_out', 'label' => 'Total Out', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
            ],
            totals: [
                'stone_code' => 'Total',
                'used_in_item' => $rows->sum('used_in_item'),
                'sold' => $rows->sum('sold'),
                'returned' => $rows->sum('returned'),
                'total_out' => $rows->sum('total_out'),
            ],
            meta: [
                'table_title' => 'Consumption by stone',
                'empty_title' => 'No stones were consumed in this period',
                'empty_message' => 'Nothing was set into a piece, sold loose or returned in the selected range.',
            ],
        );
    }

    /**
     * Full movement ledger with the before/after reconciliation columns.
     *
     * quantity_before and quantity_after are shown on every row because they
     * are the integrity check: for one stone, each row's "before" must equal
     * the previous row's "after". A break means stock was written somewhere
     * other than StoneStockService.
     */
    public function movementLedger(ReportFilter $filter): ReportResult
    {
        $rows = $this->stones->movementLedger($filter, (int) config('annigems.reports.per_page', 100))
            ->map(fn (array $row): array => [
                'created_at' => $row['created_at'],
                'stone_code' => $row['stone_code'],
                'stone_name' => $row['stone_name'],
                'type' => StoneMovementType::tryFrom($row['type'])?->label() ?? $row['type'],
                'quantity_before' => $row['quantity_before'],
                'quantity_change' => $this->signed($row['quantity_change']),
                'quantity_after' => $row['quantity_after'],
                'actor' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
                'notes' => $row['notes'],
            ]);

        $volume = $this->stones->movementVolumeByType($filter);

        return new ReportResult(
            key: 'stone-movement-ledger',
            title: 'Stone Movement Ledger',
            summary: [
                $this->card('movements', 'Movements', ReportFormatter::count($volume->sum('movements')), 'history'),
                $this->card('quantity_in', 'Stones In', ReportFormatter::count($volume->sum('quantity_in')), 'add_circle'),
                $this->card('quantity_out', 'Stones Out', ReportFormatter::count($volume->sum('quantity_out')), 'remove_circle'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'created_at', 'label' => 'When', 'format' => 'datetime'],
                ['key' => 'stone_code', 'label' => 'Code'],
                ['key' => 'stone_name', 'label' => 'Stone'],
                ['key' => 'type', 'label' => 'Movement'],
                ['key' => 'quantity_before', 'label' => 'Before', 'align' => 'right', 'format' => 'count', 'emphasis' => 'muted'],
                ['key' => 'quantity_change', 'label' => 'Change', 'align' => 'right', 'emphasis' => 'strong'],
                ['key' => 'quantity_after', 'label' => 'After', 'align' => 'right', 'format' => 'count', 'emphasis' => 'muted'],
                ['key' => 'actor', 'label' => 'By'],
                ['key' => 'notes', 'label' => 'Notes'],
            ],
            meta: [
                'table_title' => 'Movement ledger',
                'empty_title' => 'No stone movements in this period',
                'extra_tables' => [
                    [
                        'title' => 'Volume by movement type',
                        'columns' => [
                            ['key' => 'type', 'label' => 'Movement Type'],
                            ['key' => 'movements', 'label' => 'Movements', 'align' => 'right', 'format' => 'count'],
                            ['key' => 'quantity_in', 'label' => 'In', 'align' => 'right', 'format' => 'count'],
                            ['key' => 'quantity_out', 'label' => 'Out', 'align' => 'right', 'format' => 'count'],
                        ],
                        'rows' => $volume->map(fn (array $row): array => [
                            'type' => StoneMovementType::tryFrom($row['type'])?->label() ?? $row['type'],
                            'movements' => $row['movements'],
                            'quantity_in' => $row['quantity_in'],
                            'quantity_out' => $row['quantity_out'],
                        ]),
                        'totals' => [
                            'type' => 'Total',
                            'movements' => $volume->sum('movements'),
                            'quantity_in' => $volume->sum('quantity_in'),
                            'quantity_out' => $volume->sum('quantity_out'),
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Render a quantity change with an explicit sign, so an inward and an
     * outward movement are distinguishable at a glance in a dense table.
     */
    private function signed(int $change): string
    {
        return ($change > 0 ? '+' : '').number_format($change);
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
