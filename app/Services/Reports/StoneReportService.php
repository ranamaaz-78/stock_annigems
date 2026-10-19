<?php

namespace App\Services\Reports;

use App\Enums\StoneMovementType;
use App\Repositories\Reports\StoneReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;
use Illuminate\Support\Collection;

/**
 * StoneReportService
 *
 * Builds the Admin loose-stone reports.
 *
 * A lot is counted in pieces (per piece and per carat lots) or weighed in
 * grams (per gram lots, whose piece count is always zero), so every figure
 * keeps the two apart and shows each in its own unit. Stock figures read live
 * records, because they describe what is in the safe right now; they are
 * valued at the lot's own purchase rate, never at a selling price.
 */
class StoneReportService
{
    public function __construct(
        private StoneReportRepository $stones,
    ) {}

    /**
     * Pieces, grams, carat and worth of every registered lot.
     */
    public function stockSummary(ReportFilter $filter): ReportResult
    {
        $rows = $this->stones->stockSummary($filter->branchId)->map(fn (array $lot): array => $lot + [
            'rate_label' => $this->rateLabel($lot),
        ]);
        $byShape = $this->stones->stockByShape($filter->branchId);

        return new ReportResult(
            key: 'stone-stock-summary',
            title: __('Stone Stock Summary'),
            summary: [
                $this->card('stones', __('Stones in Stock'), ReportFormatter::count($rows->sum('quantity')), 'diamond',
                    hint: __(':grams by weight', ['grams' => ReportFormatter::grams($rows->sum('grams'))])),
                $this->card('carat', __('Total Carat'), ReportFormatter::carats($rows->sum('total_carat')), 'scale'),
                $this->card('value', __('Stock Value'), ReportFormatter::money($rows->sum('value')), 'payments',
                    hint: __('At each lot\'s own rate')),
                $this->card('types', __('Registered Lots'), ReportFormatter::count($rows->count()), 'category',
                    hint: __(':count shapes', ['count' => ReportFormatter::count($byShape->count())])),
            ],
            rows: $rows,
            columns: [
                ['key' => 'stone_code', 'label' => __('Code')],
                ['key' => 'name', 'label' => __('Stone')],
                ['key' => 'shape', 'label' => __('Shape')],
                ['key' => 'pricing', 'label' => __('Priced')],
                ['key' => 'on_hand_label', 'label' => __('On Hand'), 'align' => 'right', 'emphasis' => 'strong'],
                ['key' => 'rate_label', 'label' => __('Rate'), 'align' => 'right'],
                ['key' => 'total_carat', 'label' => __('Total Carat'), 'align' => 'right', 'format' => 'carats'],
                ['key' => 'value', 'label' => __('Value'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'stone_code' => __('Total'),
                'total_carat' => $rows->sum('total_carat'),
                'value' => $rows->sum('value'),
            ],
            chart: ReportResult::chart(__('Stock value by shape'), 'stones', $byShape->map(fn (array $row): array => [
                'label' => $row['shape'],
                'value' => $row['value'],
                'display' => ReportFormatter::moneyCompact($row['value']),
            ])->all()),
            meta: [
                'table_title' => __('Stones in stock'),
                'live_snapshot' => true,
                'money' => true,
                'empty_title' => __('No stones registered yet'),
                'empty_message' => __('Register a stone and record an intake to start tracking stone stock.'),
                'extra_tables' => [$this->shapeTable($byShape)],
            ],
        );
    }

    /**
     * What the stone in hand is worth, lot by lot and by pricing mode.
     */
    public function valuation(ReportFilter $filter): ReportResult
    {
        $lots = $this->stones->stockSummary($filter->branchId)->map(fn (array $lot): array => $lot + [
            'rate_label' => $this->rateLabel($lot),
        ])->sortByDesc('value')->values();
        $byMode = $lots->groupBy('pricing')->map(fn (Collection $group, string $mode): array => [
            'pricing' => $mode,
            'lots' => $group->count(),
            'pieces' => (int) $group->sum('quantity'),
            'grams' => round((float) $group->sum('grams'), 3),
            'value' => round((float) $group->sum('value'), 2),
        ])->values();
        $byShape = $this->stones->stockByShape($filter->branchId);
        $total = (float) $lots->sum('value');

        return new ReportResult(
            key: 'stone-valuation',
            title: __('Stone Valuation'),
            summary: [
                $this->card('value', __('Stock Value'), ReportFormatter::money($total), 'payments', hint: __('At purchase rate')),
                $this->card('pieces', __('Pieces'), ReportFormatter::count($lots->sum('quantity')), 'diamond'),
                $this->card('grams', __('Weight'), ReportFormatter::grams($lots->sum('grams')), 'scale'),
                $this->card('lots', __('Lots Valued'), ReportFormatter::count($lots->where('value', '>', 0)->count()), 'category',
                    hint: __(':count with no value', ['count' => ReportFormatter::count($lots->where('value', '<=', 0)->count())])),
            ],
            rows: $lots,
            columns: [
                ['key' => 'stone_code', 'label' => __('Code')],
                ['key' => 'name', 'label' => __('Stone')],
                ['key' => 'pricing', 'label' => __('Priced')],
                ['key' => 'on_hand_label', 'label' => __('On Hand'), 'align' => 'right'],
                ['key' => 'rate_label', 'label' => __('Rate'), 'align' => 'right'],
                ['key' => 'value', 'label' => __('Stock Value'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'lot_cost', 'label' => __('Lot Cost Received'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'muted'],
            ],
            totals: [
                'stone_code' => __('Total'),
                'value' => $total,
                'lot_cost' => $lots->sum('lot_cost'),
            ],
            chart: ReportResult::chart(__('Stock value by shape'), 'stones', $byShape->map(fn (array $row): array => [
                'label' => $row['shape'],
                'value' => $row['value'],
                'display' => ReportFormatter::moneyCompact($row['value']),
            ])->all()),
            meta: [
                'table_title' => __('Stock value by lot'),
                'live_snapshot' => true,
                'money' => true,
                'empty_title' => __('No stones registered yet'),
                'extra_tables' => [
                    [
                        'title' => __('Value by pricing mode'),
                        'columns' => [
                            ['key' => 'pricing', 'label' => __('Priced')],
                            ['key' => 'lots', 'label' => __('Lots'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'pieces', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'grams', 'label' => __('Grams'), 'align' => 'right', 'format' => 'grams'],
                            ['key' => 'value', 'label' => __('Value'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $byMode,
                        'totals' => [
                            'pricing' => __('Total'),
                            'lots' => $byMode->sum('lots'),
                            'pieces' => $byMode->sum('pieces'),
                            'grams' => $byMode->sum('grams'),
                            'value' => $byMode->sum('value'),
                        ],
                    ],
                    $this->shapeTable($byShape),
                ],
            ],
        );
    }

    /**
     * Lots out of stock or at/below the configured reorder threshold, each
     * judged in its own unit.
     */
    public function lowStock(ReportFilter $filter): ReportResult
    {
        $rows = $this->stones->zeroAndLowStock($filter->branchId);
        $threshold = (int) config('annigems.reports.low_stone_threshold', 5);
        $gramThreshold = (float) config('annigems.reports.low_stone_weight_threshold', 10);
        $zero = $rows->where('severity', 'Out of stock')->count();

        return new ReportResult(
            key: 'stone-low-stock',
            title: __('Zero & Low Stone Stock'),
            summary: [
                $this->card('zero', __('Out of Stock'), ReportFormatter::count($zero), 'error'),
                $this->card('low', __('Low Stock'), ReportFormatter::count($rows->count() - $zero), 'warning',
                    hint: __('At or below :pcs pcs / :grams g', ['pcs' => $threshold, 'grams' => rtrim(rtrim(number_format($gramThreshold, 3, '.', ''), '0'), '.')])),
                $this->card('carat', __('Carat Remaining'), ReportFormatter::carats($rows->sum(fn (array $row): float => $row['carat'] * $row['quantity'])), 'scale'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'stone_code', 'label' => __('Code')],
                ['key' => 'name', 'label' => __('Stone')],
                ['key' => 'shape', 'label' => __('Shape')],
                ['key' => 'carat', 'label' => __('Carat Each'), 'align' => 'right', 'format' => 'carats'],
                ['key' => 'on_hand_label', 'label' => __('On Hand'), 'align' => 'right', 'emphasis' => 'strong'],
                ['key' => 'severity', 'label' => __('Status')],
            ],
            meta: [
                'table_title' => __('Needs reordering'),
                'live_snapshot' => true,
                'empty_title' => __('Every stone is above the reorder threshold'),
                'empty_message' => __('Nothing is at or below :pcs pcs or :grams g in stock.', ['pcs' => $threshold, 'grams' => rtrim(rtrim(number_format($gramThreshold, 3, '.', ''), '0'), '.')]),
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
            'weight_grams' => $row['weight_grams'],
            'total_carat' => $row['total_carat'],
            'received_by' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
        ]);

        return new ReportResult(
            key: 'stone-intake-history',
            title: __('Stone Intake History'),
            summary: [
                $this->card('batches', __('Intake Batches'), ReportFormatter::count($rows->count()), 'move_to_inbox'),
                $this->card('quantity', __('Stones Received'), ReportFormatter::count($rows->sum('quantity')), 'diamond',
                    hint: __(':grams by weight', ['grams' => ReportFormatter::grams($rows->sum('weight_grams'))])),
                $this->card('carat', __('Carat Received'), ReportFormatter::carats($rows->sum('total_carat')), 'scale'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'intake_number', 'label' => __('Batch')],
                ['key' => 'intake_date', 'label' => __('Date')],
                ['key' => 'stone_types', 'label' => __('Lines'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'quantity', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'weight_grams', 'label' => __('Grams'), 'align' => 'right', 'format' => 'grams'],
                ['key' => 'total_carat', 'label' => __('Carat'), 'align' => 'right', 'format' => 'carats'],
                ['key' => 'received_by', 'label' => __('Received By')],
            ],
            totals: [
                'intake_number' => __('Total'),
                'stone_types' => $rows->sum('stone_types'),
                'quantity' => $rows->sum('quantity'),
                'weight_grams' => $rows->sum('weight_grams'),
                'total_carat' => $rows->sum('total_carat'),
            ],
            meta: [
                'table_title' => __('Intake batches'),
                'empty_title' => __('No stone intakes in this period'),
            ],
        );
    }

    /**
     * Which stones actually move, split by where they went (pieces and grams).
     *
     * Only out-flows count as consumption; what came back to stock is shown
     * beside it. A stone with a large quantity and zero consumption is dead
     * capital, which is precisely what this exposes.
     */
    public function consumption(ReportFilter $filter): ReportResult
    {
        $rows = $this->stones->consumption($filter);
        $sum = fn (string $key): string => $this->amount((int) $rows->sum($key), (float) $rows->sum($key.'_g'));

        $display = $rows->map(function (array $row): array {
            foreach (['used_in_item', 'sold', 'delivered', 'on_credit', 'written_off', 'other_out', 'total_out', 'back'] as $key) {
                $row[$key.'_label'] = $this->amount($row[$key], $row[$key.'_g']);
            }

            return $row;
        });

        return new ReportResult(
            key: 'stone-consumption',
            title: __('Stone Consumption'),
            summary: [
                $this->card('total_out', __('Stones Consumed'), $sum('total_out'), 'trending_down'),
                $this->card('used_in_item', __('Set Into Pieces'), $sum('used_in_item'), 'diamond'),
                $this->card('sold', __('Sold Loose'), $sum('sold'), 'sell'),
                $this->card('out', __('Out With Clients'), $this->amount(
                    (int) ($rows->sum('delivered') + $rows->sum('on_credit')),
                    (float) ($rows->sum('delivered_g') + $rows->sum('on_credit_g'))
                ), 'local_shipping', hint: __('Delivery notes and performas')),
                $this->card('back', __('Back to Stock'), $sum('back'), 'undo'),
            ],
            rows: $display,
            columns: [
                ['key' => 'stone_code', 'label' => __('Code')],
                ['key' => 'name', 'label' => __('Stone')],
                ['key' => 'shape', 'label' => __('Shape')],
                ['key' => 'used_in_item_label', 'label' => __('Set Into Pieces'), 'align' => 'right'],
                ['key' => 'sold_label', 'label' => __('Sold'), 'align' => 'right'],
                ['key' => 'delivered_label', 'label' => __('Delivered'), 'align' => 'right'],
                ['key' => 'on_credit_label', 'label' => __('On Credit'), 'align' => 'right'],
                ['key' => 'written_off_label', 'label' => __('Written Off'), 'align' => 'right'],
                ['key' => 'total_out_label', 'label' => __('Total Out'), 'align' => 'right', 'emphasis' => 'strong'],
                ['key' => 'back_label', 'label' => __('Back to Stock'), 'align' => 'right', 'emphasis' => 'muted'],
            ],
            totals: [
                'stone_code' => __('Total'),
                'used_in_item_label' => $sum('used_in_item'),
                'sold_label' => $sum('sold'),
                'delivered_label' => $sum('delivered'),
                'on_credit_label' => $sum('on_credit'),
                'written_off_label' => $sum('written_off'),
                'total_out_label' => $sum('total_out'),
                'back_label' => $sum('back'),
            ],
            meta: [
                'table_title' => __('Consumption by stone'),
                'empty_title' => __('No stones were consumed in this period'),
                'empty_message' => __('Nothing was set into a piece, sold, delivered, issued on credit or written off in the selected range.'),
            ],
        );
    }

    /**
     * Full movement ledger with the before/after reconciliation columns.
     *
     * quantity_before and quantity_after are shown on every row because they
     * are the integrity check: for one stone, each row's "before" must equal
     * the previous row's "after". A gram lot reconciles on grams instead, so
     * its rows show the weight in those same columns.
     */
    public function movementLedger(ReportFilter $filter): ReportResult
    {
        $rows = $this->stones->movementLedger($filter, (int) config('annigems.reports.per_page', 100))
            ->map(fn (array $row): array => [
                'created_at' => $row['created_at'],
                'stone_code' => $row['stone_code'],
                'stone_name' => $row['stone_name'],
                'type' => StoneMovementType::tryFrom($row['type'])?->label() ?? $row['type'],
                'quantity_before' => $row['weighed'] ? $this->g($row['weight_before']) : $row['quantity_before'],
                'quantity_change' => $row['weighed'] ? $this->signedGrams($row['weight_change']) : $this->signed($row['quantity_change']),
                'quantity_after' => $row['weighed'] ? $this->g($row['weight_after']) : $row['quantity_after'],
                'actor' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
                'notes' => $row['notes'],
            ]);

        $volume = $this->stones->movementVolumeByType($filter);

        return new ReportResult(
            key: 'stone-movement-ledger',
            title: __('Stone Movement Ledger'),
            summary: [
                $this->card('movements', __('Movements'), ReportFormatter::count($volume->sum('movements')), 'history'),
                $this->card('quantity_in', __('Stones In'), $this->amount((int) $volume->sum('quantity_in'), (float) $volume->sum('grams_in')), 'add_circle'),
                $this->card('quantity_out', __('Stones Out'), $this->amount((int) $volume->sum('quantity_out'), (float) $volume->sum('grams_out')), 'remove_circle'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'created_at', 'label' => __('When'), 'format' => 'datetime'],
                ['key' => 'stone_code', 'label' => __('Code')],
                ['key' => 'stone_name', 'label' => __('Stone')],
                ['key' => 'type', 'label' => __('Movement')],
                ['key' => 'quantity_before', 'label' => __('Before'), 'align' => 'right', 'emphasis' => 'muted'],
                ['key' => 'quantity_change', 'label' => __('Change'), 'align' => 'right', 'emphasis' => 'strong'],
                ['key' => 'quantity_after', 'label' => __('After'), 'align' => 'right', 'emphasis' => 'muted'],
                ['key' => 'actor', 'label' => __('By')],
                ['key' => 'notes', 'label' => __('Notes')],
            ],
            meta: [
                'table_title' => __('Movement ledger'),
                'empty_title' => __('No stone movements in this period'),
                'extra_tables' => [
                    [
                        'title' => __('Volume by movement type'),
                        'columns' => [
                            ['key' => 'type', 'label' => __('Movement Type')],
                            ['key' => 'movements', 'label' => __('Movements'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'quantity_in', 'label' => __('Pieces In'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'quantity_out', 'label' => __('Pieces Out'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'grams_in', 'label' => __('Grams In'), 'align' => 'right', 'format' => 'grams'],
                            ['key' => 'grams_out', 'label' => __('Grams Out'), 'align' => 'right', 'format' => 'grams'],
                        ],
                        'rows' => $volume->map(fn (array $row): array => [
                            'type' => StoneMovementType::tryFrom($row['type'])?->label() ?? $row['type'],
                            'movements' => $row['movements'],
                            'quantity_in' => $row['quantity_in'],
                            'quantity_out' => $row['quantity_out'],
                            'grams_in' => $row['grams_in'],
                            'grams_out' => $row['grams_out'],
                        ]),
                        'totals' => [
                            'type' => __('Total'),
                            'movements' => $volume->sum('movements'),
                            'quantity_in' => $volume->sum('quantity_in'),
                            'quantity_out' => $volume->sum('quantity_out'),
                            'grams_in' => $volume->sum('grams_in'),
                            'grams_out' => $volume->sum('grams_out'),
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * The "totals by shape" table shared by the stock and valuation reports.
     *
     * @param  Collection<int, array<string, mixed>>  $byShape
     * @return array<string, mixed>
     */
    private function shapeTable(Collection $byShape): array
    {
        return [
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
        ];
    }

    /**
     * "2.50 / pc", "40.00 / ct" or "5.00 / g" for a lot's rate.
     *
     * @param  array<string, mixed>  $lot
     */
    private function rateLabel(array $lot): string
    {
        return ((float) $lot['rate']) > 0
            ? ReportFormatter::money($lot['rate']).' / '.$lot['rate_unit']
            : '—';
    }

    /**
     * Pieces and grams worded together: "4", "2.5 g" or "4 + 2.5 g"; "—" for none.
     */
    private function amount(int $pieces, float $grams): string
    {
        $parts = [];

        if ($pieces > 0) {
            $parts[] = number_format($pieces);
        }

        if ($grams > 0) {
            $parts[] = rtrim(rtrim(number_format($grams, 3, '.', ''), '0'), '.').' g';
        }

        return $parts === [] ? '0' : implode(' + ', $parts);
    }

    private function g(?float $grams): string
    {
        return rtrim(rtrim(number_format((float) $grams, 3, '.', ''), '0'), '.').' g';
    }

    private function signedGrams(?float $grams): string
    {
        return ((float) $grams > 0 ? '+' : '').$this->g($grams);
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
