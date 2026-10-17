<?php

namespace App\Services\Reports;

use App\Enums\PaymentMethod;
use App\Repositories\Reports\SalesReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;
use Carbon\CarbonImmutable;

/**
 * SalesReportService
 *
 * Builds every Admin sales report. Each public method takes a ReportFilter and
 * returns a ReportResult, which the shared Blade shell and the CSV exporter
 * both render without knowing which report they are looking at.
 *
 * Two rules hold across all of them:
 *
 * - Only completed invoices count. Voided invoices are a reversed sale and are
 *   reported separately by voided(), never netted off a revenue total.
 * - Every money figure is revenue. The system records no purchase cost, so no
 *   profit or margin figure exists here and none may be added later without
 *   first capturing cost.
 *
 * No SQL lives in this class; aggregation is the repository's job.
 */
class SalesReportService
{
    public function __construct(
        private SalesReportRepository $sales,
    ) {}

    /**
     * Headline sales summary with a period-over-period trend and a time chart.
     *
     * The comparison is against the immediately preceding range of identical
     * length, so "this month vs last month" and "this week vs last week" both
     * work without a special case.
     */
    public function summary(ReportFilter $filter): ReportResult
    {
        $totals = $this->sales->totals($filter);
        $previous = $this->sales->totals($filter->previousPeriod());
        $voided = $this->sales->voidedTotals($filter);

        $rows = $this->sales->revenueTrend($filter)->map(fn (array $row): array => [
            'bucket' => $this->bucketLabel($row['bucket']),
            'invoice_count' => $row['invoice_count'],
            'net_revenue' => $row['net_revenue'],
            'average_sale' => $row['invoice_count'] > 0 ? $row['net_revenue'] / $row['invoice_count'] : 0.0,
        ]);

        return new ReportResult(
            key: 'sales-summary',
            title: 'Sales Summary',
            summary: [
                $this->card('net_revenue', 'Net Revenue', ReportFormatter::money($totals['net_revenue']), 'payments',
                    trend: ReportFormatter::trend($totals['net_revenue'], $previous['net_revenue'])),
                $this->card('invoice_count', 'Invoices', ReportFormatter::count($totals['invoice_count']), 'receipt_long',
                    trend: ReportFormatter::trend($totals['invoice_count'], $previous['invoice_count'])),
                $this->card('average_sale', 'Average Sale', ReportFormatter::money($totals['average_sale']), 'analytics'),
                $this->card('gross_sales', 'Gross Sales', ReportFormatter::money($totals['gross_sales']), 'sell',
                    hint: ReportFormatter::money($totals['discount_total']).' discount, '.ReportFormatter::money($totals['tax_total']).' tax'.($totals['recargo_total'] > 0 ? ', '.ReportFormatter::money($totals['recargo_total']).' recargo' : '')),
                $this->card('gold_weight_sold', 'Gold Weight Sold', ReportFormatter::grams($this->sales->goldWeightSold($filter)), 'scale',
                    hint: 'Net metal, revenue not profit'),
                $this->card('voided', 'Voided Invoices', ReportFormatter::count($voided['count']), 'block',
                    hint: ReportFormatter::money($voided['value']).' excluded from every total'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'bucket', 'label' => $filter->grouping->label()],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'average_sale', 'label' => 'Average Sale', 'align' => 'right', 'format' => 'money'],
                ['key' => 'net_revenue', 'label' => 'Net Revenue', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'bucket' => 'Total',
                'invoice_count' => $totals['invoice_count'],
                'average_sale' => $totals['average_sale'],
                'net_revenue' => $totals['net_revenue'],
            ],
            chart: ReportResult::chart(
                'Revenue by '.strtolower($filter->grouping->label()),
                'revenue',
                $rows->map(fn (array $row): array => [
                    'label' => $row['bucket'],
                    'value' => $row['net_revenue'],
                    'display' => ReportFormatter::moneyCompact($row['net_revenue']),
                ])->all(),
                'vertical'
            ),
            meta: [
                'table_title' => 'Revenue by period',
                'money' => true,
                'extra_tables' => [
                    [
                        'title' => 'Where the invoices came from',
                        'columns' => [
                            ['key' => 'source', 'label' => 'Source'],
                            ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                            ['key' => 'net_revenue', 'label' => 'Net Revenue', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $sources = $this->sales->bySource($filter),
                        'totals' => [
                            'source' => 'Total',
                            'invoice_count' => $sources->sum('invoice_count'),
                            'net_revenue' => $sources->sum('net_revenue'),
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Revenue split by how the customer paid, with each method's share.
     */
    public function byPaymentMethod(ReportFilter $filter): ReportResult
    {
        $totals = $this->sales->totals($filter);

        $rows = $this->sales->byPaymentMethod($filter)->map(fn (array $row): array => [
            'payment_method' => PaymentMethod::tryFrom($row['payment_method'])?->label() ?? $row['payment_method'],
            'invoice_count' => $row['invoice_count'],
            'net_revenue' => $row['net_revenue'],
            'share' => $row['share'],
        ]);

        return new ReportResult(
            key: 'sales-by-payment-method',
            title: 'Sales by Payment Method',
            summary: [
                $this->card('net_revenue', 'Net Revenue', ReportFormatter::money($totals['net_revenue']), 'payments'),
                $this->card('methods_used', 'Methods Used', ReportFormatter::count($rows->count()), 'account_balance_wallet'),
                $this->card('top_method', 'Largest Method', (string) ($rows->first()['payment_method'] ?? '—'), 'star',
                    hint: $rows->isNotEmpty() ? ReportFormatter::percent($rows->first()['share']).' of revenue' : null),
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
                'invoice_count' => $totals['invoice_count'],
                'net_revenue' => $totals['net_revenue'],
            ],
            chart: ReportResult::chart('Revenue by payment method', 'revenue', $rows->map(fn (array $row): array => [
                'label' => $row['payment_method'],
                'value' => $row['net_revenue'],
                'display' => ReportFormatter::moneyCompact($row['net_revenue']),
            ])->all()),
            meta: ['table_title' => 'Payment method breakdown', 'money' => true],
        );
    }

    /**
     * Which categories sell. Reads the category recorded on the sale, so a
     * later rename or recategorisation does not rewrite past months.
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
            meta: [
                'table_title' => 'Category breakdown',
                'money' => true,
                'empty_title' => 'No jewellery sold in this period',
                'empty_message' => 'Loose stone sales are reported separately under Loose Stone Sales.',
            ],
        );
    }

    /**
     * Seller performance. The role shown is the one held when the sale was made.
     */
    public function byStaff(ReportFilter $filter): ReportResult
    {
        $totals = $this->sales->totals($filter);

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
                $this->card('invoices', 'Invoices', ReportFormatter::count($totals['invoice_count']), 'receipt_long'),
                $this->card('revenue', 'Net Revenue', ReportFormatter::money($totals['net_revenue']), 'payments'),
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
                'invoice_count' => $totals['invoice_count'],
                'average_sale' => $totals['average_sale'],
                'net_revenue' => $totals['net_revenue'],
            ],
            chart: ReportResult::chart('Revenue by staff member', 'revenue', $rows->take(10)->map(fn (array $row): array => [
                'label' => $row['staff'],
                'value' => $row['net_revenue'],
                'display' => ReportFormatter::moneyCompact($row['net_revenue']),
            ])->all()),
            meta: ['table_title' => 'Staff breakdown', 'money' => true],
        );
    }

    /**
     * Metal actually sold, by purity.
     *
     * The figure revenue alone hides: a heavy 18K month and a light 22K month
     * can produce the same revenue but represent very different stock movement.
     */
    public function goldWeightSold(ReportFilter $filter): ReportResult
    {
        $rows = $this->sales->goldWeightSoldByPurity($filter);

        return new ReportResult(
            key: 'gold-weight-sold',
            title: 'Gold Weight Sold',
            summary: [
                $this->card('net_weight', 'Net Weight Sold', ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('gross_weight', 'Gross Weight Sold', ReportFormatter::grams($rows->sum('gross_weight')), 'monitor_weight',
                    hint: 'Includes stone weight'),
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
            meta: ['table_title' => 'Weight sold by purity', 'money' => true],
        );
    }

    /**
     * Discount given, per invoice and per staff member.
     *
     * Both views are shown together because the useful question is not just
     * "how much was discounted" but "who is discounting, and how heavily".
     */
    public function discounts(ReportFilter $filter): ReportResult
    {
        $totals = $this->sales->totals($filter);
        $perInvoice = $this->sales->discountedInvoices($filter);
        $perStaff = $this->sales->discountByStaff($filter)->map(fn (array $row): array => [
            'staff' => ReportFormatter::actor($row['staff_name'], $row['staff_role']),
            'invoice_count' => $row['invoice_count'],
            'gross_total' => $row['gross_total'],
            'discount_percent' => $row['discount_percent'],
            'discount_total' => $row['discount_total'],
        ]);

        $rows = $perInvoice->map(fn (array $row): array => [
            'invoice_number' => $row['invoice_number'],
            'invoice_date' => $row['invoice_date'],
            'customer' => $row['customer'],
            'applied_by' => ReportFormatter::actor($row['applied_by'], $row['applied_by_role']),
            'gross' => $row['gross'],
            'discount_percent' => $row['discount_percent'],
            'discount' => $row['discount'],
            'net' => $row['net'],
        ]);

        return new ReportResult(
            key: 'discount-report',
            title: 'Discount Report',
            summary: [
                $this->card('discount_total', 'Total Discount', ReportFormatter::money($totals['discount_total']), 'percent'),
                $this->card('discounted_invoices', 'Discounted Invoices', ReportFormatter::count($perInvoice->count()), 'receipt_long',
                    hint: 'of '.ReportFormatter::count($totals['invoice_count']).' invoices'),
                $this->card('discount_share', 'Discount as % of Gross',
                    ReportFormatter::percent($totals['gross_sales'] > 0 ? $totals['discount_total'] / $totals['gross_sales'] * 100 : 0),
                    'trending_down'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'invoice_number', 'label' => 'Invoice'],
                ['key' => 'invoice_date', 'label' => 'Date'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'applied_by', 'label' => 'Applied By'],
                ['key' => 'gross', 'label' => 'Gross', 'align' => 'right', 'format' => 'money'],
                ['key' => 'discount_percent', 'label' => 'Discount %', 'align' => 'right', 'format' => 'percent'],
                ['key' => 'discount', 'label' => 'Discount', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'net', 'label' => 'Net', 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'invoice_number' => 'Total',
                'gross' => $perInvoice->sum('gross'),
                'discount' => $perInvoice->sum('discount'),
                'net' => $perInvoice->sum('net'),
            ],
            meta: [
                'table_title' => 'Discounted invoices',
                'money' => true,
                'empty_title' => 'No discounts given in this period',
                'empty_message' => 'Every invoice in this range was billed at full price.',
                // Rendered by the report page as a second table below the first.
                'extra_tables' => [
                    [
                        'title' => 'Discount by staff member',
                        'columns' => [
                            ['key' => 'staff', 'label' => 'Staff Member'],
                            ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                            ['key' => 'gross_total', 'label' => 'Gross', 'align' => 'right', 'format' => 'money'],
                            ['key' => 'discount_percent', 'label' => 'Discount %', 'align' => 'right', 'format' => 'percent'],
                            ['key' => 'discount_total', 'label' => 'Discount', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $perStaff,
                        'totals' => [
                            'staff' => 'Total',
                            'invoice_count' => $perStaff->sum('invoice_count'),
                            'gross_total' => $perStaff->sum('gross_total'),
                            'discount_total' => $perStaff->sum('discount_total'),
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Tax collected per period, for filing.
     */
    public function taxCollected(ReportFilter $filter): ReportResult
    {
        $rows = $this->sales->taxCollected($filter)->map(fn (array $row): array => [
            'bucket' => $this->bucketLabel($row['bucket']),
            'invoice_count' => $row['invoice_count'],
            'taxable_base' => $row['taxable_base'],
            'tax_total' => $row['tax_total'],
            'recargo_total' => $row['recargo_total'],
            'effective_rate' => $row['taxable_base'] > 0 ? $row['tax_total'] / $row['taxable_base'] * 100 : 0.0,
        ]);

        $taxTotal = $rows->sum('tax_total');
        $recargoTotal = $rows->sum('recargo_total');
        $base = $rows->sum('taxable_base');
        $byRate = $this->sales->taxByRate($filter);

        return new ReportResult(
            key: 'tax-collected',
            title: 'Tax Collected',
            summary: [
                $this->card('tax_total', 'Tax Collected', ReportFormatter::money($taxTotal), 'receipt'),
                $this->card('taxable_base', 'Taxable Base', ReportFormatter::money($base), 'sell',
                    hint: 'Gross less discount'),
                $this->card('recargo_total', 'Recargo Collected', ReportFormatter::money($recargoTotal), 'add_card',
                    hint: 'Recargo de equivalencia'),
                $this->card('effective_rate', 'Effective Rate',
                    ReportFormatter::percent($base > 0 ? $taxTotal / $base * 100 : 0), 'percent'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'bucket', 'label' => $filter->grouping->label()],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'taxable_base', 'label' => 'Taxable Base', 'align' => 'right', 'format' => 'money'],
                ['key' => 'effective_rate', 'label' => 'Rate', 'align' => 'right', 'format' => 'percent'],
                ['key' => 'tax_total', 'label' => 'IVA', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'recargo_total', 'label' => 'Recargo', 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'bucket' => 'Total',
                'invoice_count' => $rows->sum('invoice_count'),
                'taxable_base' => $base,
                'tax_total' => $taxTotal,
                'recargo_total' => $recargoTotal,
            ],
            chart: ReportResult::chart('Tax collected by '.strtolower($filter->grouping->label()), 'tax', $rows->map(fn (array $row): array => [
                'label' => $row['bucket'],
                'value' => $row['tax_total'],
                'display' => ReportFormatter::moneyCompact($row['tax_total']),
            ])->all(), 'vertical'),
            meta: [
                'table_title' => 'Tax by period',
                'money' => true,
                'extra_tables' => [
                    [
                        'title' => 'By rate',
                        'columns' => [
                            ['key' => 'tax_name', 'label' => 'IVA'],
                            ['key' => 'tax_rate', 'label' => 'Rate', 'align' => 'right', 'format' => 'percent'],
                            ['key' => 'recargo_name', 'label' => 'Recargo'],
                            ['key' => 'recargo_rate', 'label' => 'Rate', 'align' => 'right', 'format' => 'percent'],
                            ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                            ['key' => 'taxable_base', 'label' => 'Taxable Base', 'align' => 'right', 'format' => 'money'],
                            ['key' => 'tax_total', 'label' => 'IVA', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                            ['key' => 'recargo_total', 'label' => 'Recargo', 'align' => 'right', 'format' => 'money'],
                        ],
                        'rows' => $byRate,
                        'totals' => [
                            'tax_name' => 'Total',
                            'invoice_count' => $byRate->sum('invoice_count'),
                            'taxable_base' => $byRate->sum('taxable_base'),
                            'tax_total' => $byRate->sum('tax_total'),
                            'recargo_total' => $byRate->sum('recargo_total'),
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Voided invoices — the reversed sales, kept out of every revenue figure.
     *
     * A void is an exception worth reviewing: what was reversed, why, by whom,
     * and in which role. That is the entire content of this report.
     */
    public function voided(ReportFilter $filter): ReportResult
    {
        $totals = $this->sales->voidedTotals($filter);
        $completed = $this->sales->totals($filter);

        $rows = $this->sales->voidedInvoices($filter)->map(fn (array $row): array => [
            'invoice_number' => $row['invoice_number'],
            'invoice_date' => $row['invoice_date'],
            'customer' => $row['customer'],
            'original_value' => $row['original_value'],
            'void_reason' => $row['void_reason'],
            'voided_by' => ReportFormatter::actor($row['voided_by'], $row['voided_by_role']),
            'voided_at' => $row['voided_at'],
        ]);

        $issued = $completed['invoice_count'] + $totals['count'];

        return new ReportResult(
            key: 'voided-invoices',
            title: 'Voided Invoices',
            summary: [
                $this->card('void_count', 'Voided Invoices', ReportFormatter::count($totals['count']), 'block'),
                $this->card('void_value', 'Value Reversed', ReportFormatter::money($totals['value']), 'money_off',
                    hint: 'Excluded from all revenue figures'),
                $this->card('void_rate', 'Void Rate',
                    ReportFormatter::percent($issued > 0 ? $totals['count'] / $issued * 100 : 0), 'percent',
                    hint: 'of all invoices raised'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'invoice_number', 'label' => 'Invoice'],
                ['key' => 'invoice_date', 'label' => 'Invoice Date'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'void_reason', 'label' => 'Reason'],
                ['key' => 'voided_by', 'label' => 'Voided By'],
                ['key' => 'voided_at', 'label' => 'Voided At', 'format' => 'datetime'],
                ['key' => 'original_value', 'label' => 'Original Value', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'invoice_number' => 'Total',
                'original_value' => $totals['value'],
            ],
            meta: [
                'table_title' => 'Voided invoices',
                'money' => true,
                'empty_title' => 'No invoices were voided in this period',
                'empty_message' => 'Every invoice raised in this range still stands.',
            ],
        );
    }

    /**
     * Stones sold loose rather than set into a piece, in pieces or grams.
     */
    public function looseStoneSales(ReportFilter $filter): ReportResult
    {
        $rows = $this->sales->looseStoneSales($filter)->map(fn (array $row): array => $row + [
            'sold_label' => $this->amount($row['quantity'], $row['grams']),
        ]);

        return new ReportResult(
            key: 'loose-stone-sales',
            title: 'Loose Stone Sales',
            summary: [
                $this->card('quantity', 'Pieces Sold', ReportFormatter::count($rows->sum('quantity')), 'diamond'),
                $this->card('grams', 'Grams Sold', ReportFormatter::grams($rows->sum('grams')), 'scale'),
                $this->card('revenue', 'Revenue', ReportFormatter::money($rows->sum('revenue')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'stone_code', 'label' => 'Code'],
                ['key' => 'stone_name', 'label' => 'Stone'],
                ['key' => 'shape', 'label' => 'Shape'],
                ['key' => 'quantity', 'label' => 'Pieces', 'align' => 'right', 'format' => 'count'],
                ['key' => 'grams', 'label' => 'Grams', 'align' => 'right', 'format' => 'grams'],
                ['key' => 'revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'stone_code' => 'Total',
                'quantity' => $rows->sum('quantity'),
                'grams' => $rows->sum('grams'),
                'revenue' => $rows->sum('revenue'),
            ],
            meta: [
                'table_title' => 'Loose stone sales',
                'money' => true,
                'empty_title' => 'No loose stones sold in this period',
                'empty_message' => 'Stones set into finished pieces are counted under Sales by Category.',
            ],
        );
    }

    /**
     * Pieces and grams worded together: "4", "2.5 g" or "4 + 2.5 g".
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

    /**
     * Turn a SQL bucket key into something readable on an axis.
     *
     * Day buckets are ISO dates and become "05 Sep"; week buckets ("2026-36")
     * and month buckets ("2026-09") are already short enough to show as they
     * are, so they pass through untouched.
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
