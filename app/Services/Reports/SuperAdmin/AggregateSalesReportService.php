<?php

namespace App\Services\Reports\SuperAdmin;

use App\Enums\PaymentMethod;
use App\Repositories\Reports\SalesReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;
use Carbon\CarbonImmutable;

/**
 * AggregateSalesReportService
 *
 * SuperAdmin sales reports. Every table here is grouped — by branch, by period,
 * by payment method, by category, by purity, by staff — and none of them can
 * name an invoice. Compare with the Admin SalesReportService, which also offers
 * per-invoice discount and void listings: those methods are simply absent here
 * rather than gated, so there is no flag to get wrong.
 */
class AggregateSalesReportService extends AggregateReportService
{
    public function __construct(
        private SalesReportRepository $sales,
    ) {}

    /**
     * Consolidated sales with a branch-by-branch breakdown.
     */
    public function summary(ReportFilter $filter): ReportResult
    {
        $totals = $this->sales->totals($filter);
        $previous = $this->sales->totals($filter->previousPeriod());

        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $branchTotals = $this->sales->totals($scoped);

            return [
                'invoice_count' => $branchTotals['invoice_count'],
                'average_sale' => $branchTotals['average_sale'],
                'gold_weight_sold' => $this->sales->goldWeightSold($scoped),
                'net_revenue' => $branchTotals['net_revenue'],
            ];
        });

        return new ReportResult(
            key: 'sales-summary',
            title: 'Sales Summary',
            summary: [
                $this->card('net_revenue', 'Net Revenue', ReportFormatter::money($totals['net_revenue']), 'payments',
                    trend: ReportFormatter::trend($totals['net_revenue'], $previous['net_revenue'])),
                $this->card('invoice_count', 'Invoices', ReportFormatter::count($totals['invoice_count']), 'receipt_long',
                    trend: ReportFormatter::trend($totals['invoice_count'], $previous['invoice_count'])),
                $this->card('average_sale', 'Average Sale', ReportFormatter::money($totals['average_sale']), 'analytics'),
                $this->card('gold_weight_sold', 'Gold Weight Sold', ReportFormatter::grams($this->sales->goldWeightSold($filter)), 'scale',
                    hint: 'Net metal, revenue not profit'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'average_sale', 'label' => 'Average Sale', 'align' => 'right', 'format' => 'money'],
                ['key' => 'gold_weight_sold', 'label' => 'Gold Sold', 'align' => 'right', 'format' => 'grams'],
                ['key' => 'net_revenue', 'label' => 'Net Revenue', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'branch' => 'All Branches',
                'invoice_count' => $rows->sum('invoice_count'),
                'gold_weight_sold' => $rows->sum('gold_weight_sold'),
                'net_revenue' => $rows->sum('net_revenue'),
            ],
            chart: ReportResult::chart(
                'Revenue by '.strtolower($filter->grouping->label()),
                'revenue',
                $this->sales->revenueTrend($filter)->map(fn (array $row): array => [
                    'label' => $this->bucketLabel($row['bucket']),
                    'value' => $row['net_revenue'],
                    'display' => ReportFormatter::moneyCompact($row['net_revenue']),
                ])->all(),
                'vertical'
            ),
            meta: $this->meta(['table_title' => 'Revenue by branch', 'money' => true]),
        );
    }

    /**
     * Revenue by payment method across the selected scope.
     */
    public function byPaymentMethod(ReportFilter $filter): ReportResult
    {
        $rows = $this->sales->byPaymentMethod($filter)->map(fn (array $row): array => [
            'payment_method' => PaymentMethod::tryFrom($row['payment_method'])?->label() ?? $row['payment_method'],
            'invoice_count' => $row['invoice_count'],
            'share' => $row['share'],
            'net_revenue' => $row['net_revenue'],
        ]);

        return new ReportResult(
            key: 'sales-by-payment-method',
            title: 'Sales by Payment Method',
            summary: [
                $this->card('net_revenue', 'Net Revenue', ReportFormatter::money($rows->sum('net_revenue')), 'payments'),
                $this->card('methods', 'Methods Used', ReportFormatter::count($rows->count()), 'account_balance_wallet'),
                $this->card('top_method', 'Largest Method', (string) ($rows->first()['payment_method'] ?? '—'), 'star'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'payment_method', 'label' => 'Payment Method'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'share', 'label' => 'Share', 'align' => 'right', 'format' => 'percent'],
                ['key' => 'net_revenue', 'label' => 'Net Revenue', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'payment_method' => 'Total',
                'invoice_count' => $rows->sum('invoice_count'),
                'net_revenue' => $rows->sum('net_revenue'),
            ],
            chart: ReportResult::chart('Revenue by payment method', 'revenue', $rows->map(fn (array $row): array => [
                'label' => $row['payment_method'],
                'value' => $row['net_revenue'],
                'display' => ReportFormatter::moneyCompact($row['net_revenue']),
            ])->all()),
            meta: $this->meta(['table_title' => 'Payment method breakdown', 'money' => true]),
        );
    }

    /**
     * Revenue by jewellery category, read from the sale snapshots.
     */
    public function byCategory(ReportFilter $filter): ReportResult
    {
        $rows = $this->sales->byCategory($filter);

        return new ReportResult(
            key: 'sales-by-category',
            title: 'Sales by Category',
            summary: [
                $this->card('categories', 'Categories Sold', ReportFormatter::count($rows->count()), 'category'),
                $this->card('pieces', 'Pieces Sold', ReportFormatter::count($rows->sum('pieces')), 'shopping_bag'),
                $this->card('revenue', 'Revenue', ReportFormatter::money($rows->sum('revenue')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'pieces', 'label' => 'Pieces', 'align' => 'right', 'format' => 'count'],
                ['key' => 'revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'category' => 'Total',
                'pieces' => $rows->sum('pieces'),
                'revenue' => $rows->sum('revenue'),
            ],
            chart: ReportResult::chart('Revenue by category', 'revenue', $rows->take(10)->map(fn (array $row): array => [
                'label' => $row['category'],
                'value' => $row['revenue'],
                'display' => ReportFormatter::moneyCompact($row['revenue']),
            ])->all()),
            meta: $this->meta(['table_title' => 'Category breakdown', 'money' => true]),
        );
    }

    /**
     * Seller performance, with the role held at the time of each sale.
     *
     * A staff member is not a branch record in the sense spec 7.4 protects —
     * this is a performance aggregate, not a transaction — but no invoice is
     * reachable from it.
     */
    public function byStaff(ReportFilter $filter): ReportResult
    {
        $rows = $this->sales->byStaff($filter)->map(fn (array $row): array => [
            'staff' => ReportFormatter::actor($row['staff_name'], $row['staff_role'], $row['user_status'], $row['user_deleted_at']),
            'invoice_count' => $row['invoice_count'],
            'average_sale' => $row['average_sale'],
            'net_revenue' => $row['net_revenue'],
        ]);

        return new ReportResult(
            key: 'sales-by-staff',
            title: 'Sales by Staff',
            summary: [
                $this->card('sellers', 'Sellers Active', ReportFormatter::count($rows->count()), 'group'),
                $this->card('invoices', 'Invoices', ReportFormatter::count($rows->sum('invoice_count')), 'receipt_long'),
                $this->card('revenue', 'Net Revenue', ReportFormatter::money($rows->sum('net_revenue')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'staff', 'label' => 'Staff Member'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'average_sale', 'label' => 'Average Sale', 'align' => 'right', 'format' => 'money'],
                ['key' => 'net_revenue', 'label' => 'Net Revenue', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'staff' => 'Total',
                'invoice_count' => $rows->sum('invoice_count'),
                'net_revenue' => $rows->sum('net_revenue'),
            ],
            meta: $this->meta(['table_title' => 'Staff breakdown', 'money' => true]),
        );
    }

    /**
     * Metal sold, by purity.
     */
    public function goldWeightSold(ReportFilter $filter): ReportResult
    {
        $rows = $this->sales->goldWeightSoldByPurity($filter);

        return new ReportResult(
            key: 'gold-weight-sold',
            title: 'Gold Weight Sold',
            summary: [
                $this->card('net_weight', 'Net Weight Sold', ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('pieces', 'Pieces Sold', ReportFormatter::count($rows->sum('pieces')), 'shopping_bag'),
                $this->card('revenue', 'Revenue', ReportFormatter::money($rows->sum('revenue')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'purity', 'label' => 'Purity'],
                ['key' => 'pieces', 'label' => 'Pieces', 'align' => 'right', 'format' => 'count'],
                ['key' => 'gross_weight', 'label' => 'Gross Weight', 'align' => 'right', 'format' => 'grams'],
                ['key' => 'net_weight', 'label' => 'Net Weight', 'align' => 'right', 'format' => 'grams', 'emphasis' => 'strong'],
                ['key' => 'revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'purity' => 'Total',
                'pieces' => $rows->sum('pieces'),
                'gross_weight' => $rows->sum('gross_weight'),
                'net_weight' => $rows->sum('net_weight'),
                'revenue' => $rows->sum('revenue'),
            ],
            chart: ReportResult::chart('Net weight sold by purity', 'grams', $rows->map(fn (array $row): array => [
                'label' => $row['purity'],
                'value' => $row['net_weight'],
                'display' => ReportFormatter::grams($row['net_weight']),
            ])->all()),
            meta: $this->meta(['table_title' => 'Weight sold by purity', 'money' => true]),
        );
    }

    /**
     * Discounting per branch and per staff member.
     *
     * The Admin version also lists every discounted invoice. That listing has
     * no counterpart here: only the two aggregates exist.
     */
    public function discounts(ReportFilter $filter): ReportResult
    {
        $totals = $this->sales->totals($filter);

        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $branchTotals = $this->sales->totals($scoped);

            return [
                'invoice_count' => $branchTotals['invoice_count'],
                'gross_sales' => $branchTotals['gross_sales'],
                'discount_percent' => $branchTotals['gross_sales'] > 0
                    ? $branchTotals['discount_total'] / $branchTotals['gross_sales'] * 100
                    : 0.0,
                'discount_total' => $branchTotals['discount_total'],
            ];
        });

        $byStaff = $this->sales->discountByStaff($filter)->map(fn (array $row): array => [
            'staff' => ReportFormatter::actor($row['staff_name'], $row['staff_role']),
            'invoice_count' => $row['invoice_count'],
            'gross_total' => $row['gross_total'],
            'discount_percent' => $row['discount_percent'],
            'discount_total' => $row['discount_total'],
        ]);

        return new ReportResult(
            key: 'discount-report',
            title: 'Discount Report',
            summary: [
                $this->card('discount_total', 'Total Discount', ReportFormatter::money($totals['discount_total']), 'percent'),
                $this->card('gross_sales', 'Gross Sales', ReportFormatter::money($totals['gross_sales']), 'sell'),
                $this->card('discount_share', 'Discount as % of Gross',
                    ReportFormatter::percent($totals['gross_sales'] > 0 ? $totals['discount_total'] / $totals['gross_sales'] * 100 : 0),
                    'trending_down'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'gross_sales', 'label' => 'Gross', 'align' => 'right', 'format' => 'money'],
                ['key' => 'discount_percent', 'label' => 'Discount %', 'align' => 'right', 'format' => 'percent'],
                ['key' => 'discount_total', 'label' => 'Discount', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'branch' => 'All Branches',
                'invoice_count' => $rows->sum('invoice_count'),
                'gross_sales' => $rows->sum('gross_sales'),
                'discount_total' => $rows->sum('discount_total'),
            ],
            meta: $this->meta([
                'table_title' => 'Discounting by branch',
                'money' => true,
                'extra_tables' => [
                    [
                        'title' => 'Discounting by staff member',
                        'columns' => [
                            ['key' => 'staff', 'label' => 'Staff Member'],
                            ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                            ['key' => 'gross_total', 'label' => 'Gross', 'align' => 'right', 'format' => 'money'],
                            ['key' => 'discount_percent', 'label' => 'Discount %', 'align' => 'right', 'format' => 'percent'],
                            ['key' => 'discount_total', 'label' => 'Discount', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $byStaff,
                        'totals' => [
                            'staff' => 'Total',
                            'invoice_count' => $byStaff->sum('invoice_count'),
                            'gross_total' => $byStaff->sum('gross_total'),
                            'discount_total' => $byStaff->sum('discount_total'),
                        ],
                    ],
                ],
            ]),
        );
    }

    /**
     * Tax collected per period bucket.
     */
    public function taxCollected(ReportFilter $filter): ReportResult
    {
        $rows = $this->sales->taxCollected($filter)->map(fn (array $row): array => [
            'bucket' => $this->bucketLabel($row['bucket']),
            'invoice_count' => $row['invoice_count'],
            'taxable_base' => $row['taxable_base'],
            'tax_total' => $row['tax_total'],
        ]);

        return new ReportResult(
            key: 'tax-collected',
            title: 'Tax Collected',
            summary: [
                $this->card('tax_total', 'Tax Collected', ReportFormatter::money($rows->sum('tax_total')), 'receipt'),
                $this->card('taxable_base', 'Taxable Base', ReportFormatter::money($rows->sum('taxable_base')), 'sell',
                    hint: 'Gross less discount'),
                $this->card('invoices', 'Invoices', ReportFormatter::count($rows->sum('invoice_count')), 'receipt_long'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'bucket', 'label' => $filter->grouping->label()],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'taxable_base', 'label' => 'Taxable Base', 'align' => 'right', 'format' => 'money'],
                ['key' => 'tax_total', 'label' => 'Tax', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'bucket' => 'Total',
                'invoice_count' => $rows->sum('invoice_count'),
                'taxable_base' => $rows->sum('taxable_base'),
                'tax_total' => $rows->sum('tax_total'),
            ],
            chart: ReportResult::chart('Tax by '.strtolower($filter->grouping->label()), 'tax', $rows->map(fn (array $row): array => [
                'label' => $row['bucket'],
                'value' => $row['tax_total'],
                'display' => ReportFormatter::moneyCompact($row['tax_total']),
            ])->all(), 'vertical'),
            meta: $this->meta(['table_title' => 'Tax by period', 'money' => true]),
        );
    }

    /**
     * Voided invoice counts and values per branch.
     *
     * Counts only — the Admin report lists each void with its reason, which is
     * a record listing and therefore has no SuperAdmin equivalent.
     */
    public function voided(ReportFilter $filter): ReportResult
    {
        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $voided = $this->sales->voidedTotals($scoped);
            $completed = $this->sales->totals($scoped);
            $issued = $completed['invoice_count'] + $voided['count'];

            return [
                'void_count' => $voided['count'],
                'completed_count' => $completed['invoice_count'],
                'void_rate' => $issued > 0 ? $voided['count'] / $issued * 100 : 0.0,
                'void_value' => $voided['value'],
            ];
        });

        return new ReportResult(
            key: 'voided-invoices',
            title: 'Voided Invoices',
            summary: [
                $this->card('void_count', 'Voided Invoices', ReportFormatter::count($rows->sum('void_count')), 'block'),
                $this->card('void_value', 'Value Reversed', ReportFormatter::money($rows->sum('void_value')), 'money_off',
                    hint: 'Excluded from all revenue figures'),
                $this->card('void_rate', 'Void Rate',
                    ReportFormatter::percent(
                        ($rows->sum('void_count') + $rows->sum('completed_count')) > 0
                            ? $rows->sum('void_count') / ($rows->sum('void_count') + $rows->sum('completed_count')) * 100
                            : 0
                    ), 'percent'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'completed_count', 'label' => 'Completed', 'align' => 'right', 'format' => 'count'],
                ['key' => 'void_count', 'label' => 'Voided', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'void_rate', 'label' => 'Void Rate', 'align' => 'right', 'format' => 'percent'],
                ['key' => 'void_value', 'label' => 'Value Reversed', 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'branch' => 'All Branches',
                'completed_count' => $rows->sum('completed_count'),
                'void_count' => $rows->sum('void_count'),
                'void_value' => $rows->sum('void_value'),
            ],
            meta: $this->meta(['table_title' => 'Voids by branch', 'money' => true]),
        );
    }

    /**
     * Turn a SQL bucket key into something readable on an axis.
     */
    private function bucketLabel(string $bucket): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bucket) === 1) {
            return CarbonImmutable::parse($bucket)->format('d M');
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $bucket, $matches) === 1) {
            return CarbonImmutable::create((int) $matches[1], (int) $matches[2], 1)->format('M Y');
        }

        return $bucket;
    }
}
