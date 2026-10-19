<?php

namespace App\Services\Reports\SuperAdmin;

use App\Repositories\Reports\InventoryReportRepository;
use App\Repositories\Reports\QuotationReportRepository;
use App\Repositories\Reports\SalesReportRepository;
use App\Repositories\Reports\StoneReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * BranchComparisonReportService
 *
 * The two reports that only exist for SuperAdmin, because they only make sense
 * above a single branch: Branch Comparison and Stock Distribution.
 *
 * Both are pure aggregates and neither emits a link. The approved
 * superadmin_dashboard mockup has a per-row visibility action on the branch
 * table; it is deliberately absent here and on the dashboard, because spec 7.4
 * forbids drilling from an aggregate into a branch's records.
 *
 * Note also which figures are shown. The mockup's "Total Stock Value" column is
 * replaced by Gold Weight in Stock: the system captures no purchase cost, so a
 * valuation cannot be produced without inventing one.
 */
class BranchComparisonReportService extends AggregateReportService
{
    public function __construct(
        private SalesReportRepository $sales,
        private InventoryReportRepository $inventory,
        private StoneReportRepository $stones,
        private QuotationReportRepository $quotations,
    ) {}

    /**
     * Every branch side by side on the figures that matter for the period.
     */
    public function branchComparison(ReportFilter $filter): ReportResult
    {
        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $totals = $this->sales->totals($scoped);
            $funnel = $this->quotations->funnelTotals($scoped);

            return [
                'invoices' => $totals['invoice_count'],
                'average_sale' => $totals['average_sale'],
                'pieces_sold' => $this->sales->piecesSold($scoped),
                'gold_sold' => $this->sales->goldWeightSold($scoped),
                'conversion_rate' => $funnel['conversion_rate'],
                'available_pieces' => $this->inventory->availablePieceCount($scoped->branchId),
                'net_revenue' => $totals['net_revenue'],
            ];
        })->sortByDesc('net_revenue')->values();

        $revenue = $rows->sum('net_revenue');

        return new ReportResult(
            key: 'branch-comparison',
            title: __('Branch Comparison'),
            summary: [
                $this->card('branches', __('Branches Compared'), ReportFormatter::count($rows->count()), 'store'),
                $this->card('revenue', __('Combined Revenue'), ReportFormatter::money($revenue), 'payments'),
                $this->card('leader', __('Highest Revenue'), (string) ($rows->first()['branch'] ?? '—'), 'emoji_events',
                    hint: $rows->isNotEmpty() ? ReportFormatter::money($rows->first()['net_revenue']) : null),
                $this->card('average', __('Average per Branch'),
                    ReportFormatter::money($rows->count() > 0 ? $revenue / $rows->count() : 0), 'analytics'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'invoices', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'average_sale', 'label' => __('Average Sale'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'pieces_sold', 'label' => __('Pieces Sold'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'gold_sold', 'label' => __('Gold Sold'), 'align' => 'right', 'format' => 'grams'],
                ['key' => 'conversion_rate', 'label' => __('Quote Conversion'), 'align' => 'right', 'format' => 'percent'],
                ['key' => 'available_pieces', 'label' => __('Pieces in Stock'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'net_revenue', 'label' => __('Net Revenue'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'branch' => 'All Branches',
                'invoices' => $rows->sum('invoices'),
                'pieces_sold' => $rows->sum('pieces_sold'),
                'gold_sold' => $rows->sum('gold_sold'),
                'available_pieces' => $rows->sum('available_pieces'),
                'net_revenue' => $revenue,
            ],
            chart: ReportResult::chart(__('Revenue by branch'), 'revenue', $rows->map(fn (array $row): array => [
                'label' => $row['branch'],
                'value' => $row['net_revenue'],
                'display' => ReportFormatter::moneyCompact($row['net_revenue']),
            ])->all()),
            meta: $this->meta(['table_title' => __('Branch performance'), 'money' => true]),
        );
    }

    /**
     * Where stock actually sits.
     *
     * A branch holding a third of the group's metal while making a tenth of the
     * sales is the situation this report exists to make visible — and the only
     * honest way to say it without cost data is in grams and piece counts.
     */
    public function stockDistribution(ReportFilter $filter): ReportResult
    {
        $rows = $this->perBranch($filter, fn (ReportFilter $scoped): array => [
            'total_pieces' => $this->inventory->totalPieceCount($scoped->branchId),
            'available_pieces' => $this->inventory->availablePieceCount($scoped->branchId),
            'gold_weight' => $this->inventory->goldWeightInStock($scoped->branchId),
            'stones' => $this->stones->totalStonesInStock($scoped->branchId),
            'stone_carat' => $this->stones->totalCaratInStock($scoped->branchId),
            'stone_grams' => $this->stones->totalStockGrams($scoped->branchId),
        ]);

        $totalGold = $rows->sum('gold_weight');

        // Share of the group's metal, computed here so the table can show
        // concentration directly rather than making the reader divide.
        $rows = $rows->map(fn (array $row): array => array_merge($row, [
            'gold_share' => $totalGold > 0 ? $row['gold_weight'] / $totalGold * 100 : 0.0,
        ]))->sortByDesc('gold_weight')->values();

        return new ReportResult(
            key: 'stock-distribution',
            title: __('Stock Distribution'),
            summary: [
                $this->card('pieces', __('Available Pieces'), ReportFormatter::count($rows->sum('available_pieces')), 'inventory_2'),
                $this->card('gold', __('Gold Weight in Stock'), ReportFormatter::grams($totalGold), 'scale'),
                $this->card('stones', __('Stones in Stock'), ReportFormatter::count($rows->sum('stones')), 'diamond',
                    hint: __(':carats held, :grams by weight', ['carats' => ReportFormatter::carats($rows->sum('stone_carat')), 'grams' => ReportFormatter::grams($rows->sum('stone_grams'))])),
                $this->card('concentration', __('Largest Holding'),
                    $rows->isNotEmpty() ? ReportFormatter::percent($rows->first()['gold_share']) : '—', 'pie_chart',
                    hint: $rows->isNotEmpty() ? __(':branch holds this share of the metal', ['branch' => $rows->first()['branch']]) : null),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'total_pieces', 'label' => __('Total Pieces'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'available_pieces', 'label' => __('Available'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'gold_weight', 'label' => __('Gold in Stock'), 'align' => 'right', 'format' => 'grams', 'emphasis' => 'strong'],
                ['key' => 'gold_share', 'label' => __('Share of Metal'), 'align' => 'right', 'format' => 'percent'],
                ['key' => 'stones', 'label' => __('Stones'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'stone_grams', 'label' => __('Stone Grams'), 'align' => 'right', 'format' => 'grams'],
                ['key' => 'stone_carat', 'label' => __('Stone Carat'), 'align' => 'right', 'format' => 'carats'],
            ],
            totals: [
                'branch' => 'All Branches',
                'total_pieces' => $rows->sum('total_pieces'),
                'available_pieces' => $rows->sum('available_pieces'),
                'gold_weight' => $totalGold,
                'stones' => $rows->sum('stones'),
                'stone_grams' => $rows->sum('stone_grams'),
                'stone_carat' => $rows->sum('stone_carat'),
            ],
            chart: ReportResult::chart(__('Gold weight held by branch'), 'grams', $rows->map(fn (array $row): array => [
                'label' => $row['branch'],
                'value' => $row['gold_weight'],
                'display' => ReportFormatter::grams($row['gold_weight']),
            ])->all()),
            meta: $this->meta([
                'table_title' => __('Stock by branch'),
                'live_snapshot' => true,
                'empty_title' => __('No stock recorded yet'),
            ]),
        );
    }
}
