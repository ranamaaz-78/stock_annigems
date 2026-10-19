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
        $awaiting = $this->sales->awaitingPayment($filter->branchId);

        $rows = $this->sales->revenueTrend($filter)->map(fn (array $row): array => [
            'bucket' => $this->bucketLabel($row['bucket']),
            'invoice_count' => $row['invoice_count'],
            'net_revenue' => $row['net_revenue'],
            'average_sale' => $row['invoice_count'] > 0 ? $row['net_revenue'] / $row['invoice_count'] : 0.0,
        ]);

        return new ReportResult(
            key: 'sales-summary',
            title: __('Sales Summary'),
            summary: [
                $this->card('net_revenue', __('Net Revenue'), ReportFormatter::money($totals['net_revenue']), 'payments',
                    trend: ReportFormatter::trend($totals['net_revenue'], $previous['net_revenue'])),
                $this->card('invoice_count', __('Invoices'), ReportFormatter::count($totals['invoice_count']), 'receipt_long',
                    trend: ReportFormatter::trend($totals['invoice_count'], $previous['invoice_count'])),
                $this->card('average_sale', __('Average Sale'), ReportFormatter::money($totals['average_sale']), 'analytics'),
                $this->card('gross_sales', __('Gross Sales'), ReportFormatter::money($totals['gross_sales']), 'sell',
                    hint: __(':discount discount, :tax tax', ['discount' => ReportFormatter::money($totals['discount_total']), 'tax' => ReportFormatter::money($totals['tax_total'])]).($totals['recargo_total'] > 0 ? ', '.__(':value recargo', ['value' => ReportFormatter::money($totals['recargo_total'])]) : '')),
                $this->card('gold_weight_sold', __('Gold Weight Sold'), ReportFormatter::grams($this->sales->goldWeightSold($filter)), 'scale',
                    hint: __('Net metal, revenue not profit')),
                $this->card('awaiting', __('Awaiting Payment'), ReportFormatter::money($awaiting['invoice_due'] + $awaiting['note_due']), 'hourglass_top',
                    hint: __(':invoices invoices, :notes delivery notes', ['invoices' => ReportFormatter::count($awaiting['invoices']), 'notes' => ReportFormatter::count($awaiting['notes'])])),
                $this->card('voided', __('Voided Invoices'), ReportFormatter::count($voided['count']), 'block',
                    hint: __(':value excluded from every total', ['value' => ReportFormatter::money($voided['value'])])),
            ],
            rows: $rows,
            columns: [
                ['key' => 'bucket', 'label' => $filter->grouping->label()],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'average_sale', 'label' => __('Average Sale'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'net_revenue', 'label' => __('Net Revenue'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'bucket' => __('Total'),
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
                'table_title' => __('Revenue by period'),
                'money' => true,
                'extra_tables' => [
                    [
                        'title' => __('Where the invoices came from'),
                        'columns' => [
                            ['key' => 'source', 'label' => __('Source')],
                            ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'net_revenue', 'label' => __('Net Revenue'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $sources = $this->sales->bySource($filter),
                        'totals' => [
                            'source' => __('Total'),
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
            'payment_method' => PaymentMethod::tryFrom($row['payment_method'])?->label() ?? ($row['payment_method'] === 'pending' ? 'Pending (not paid yet)' : $row['payment_method']),
            'invoice_count' => $row['invoice_count'],
            'net_revenue' => $row['net_revenue'],
            'share' => $row['share'],
        ]);

        return new ReportResult(
            key: 'sales-by-payment-method',
            title: __('Sales by Payment Method'),
            summary: [
                $this->card('net_revenue', __('Net Revenue'), ReportFormatter::money($totals['net_revenue']), 'payments'),
                $this->card('methods_used', __('Methods Used'), ReportFormatter::count($rows->count()), 'account_balance_wallet'),
                $this->card('top_method', __('Largest Method'), (string) ($rows->first()['payment_method'] ?? '—'), 'star',
                    hint: $rows->isNotEmpty() ? __(':value of revenue', ['value' => ReportFormatter::percent($rows->first()['share'])]) : null),
            ],
            rows: $rows,
            columns: [
                ['key' => 'payment_method', 'label' => __('Payment Method')],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'share', 'label' => __('Share'), 'align' => 'right', 'format' => 'percent'],
                ['key' => 'net_revenue', 'label' => __('Net Revenue'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'payment_method' => __('Total'),
                'invoice_count' => $totals['invoice_count'],
                'net_revenue' => $totals['net_revenue'],
            ],
            chart: ReportResult::chart(__('Revenue by payment method'), 'revenue', $rows->map(fn (array $row): array => [
                'label' => $row['payment_method'],
                'value' => $row['net_revenue'],
                'display' => ReportFormatter::moneyCompact($row['net_revenue']),
            ])->all()),
            meta: ['table_title' => __('Payment method breakdown'), 'money' => true],
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
            title: __('Sales by Category'),
            summary: [
                $this->card('categories', __('Categories Sold'), ReportFormatter::count($rows->count()), 'category'),
                $this->card('pieces', __('Pieces Sold'), ReportFormatter::count($rows->sum('pieces')), 'shopping_bag'),
                $this->card('revenue', __('Revenue'), ReportFormatter::money($rows->sum('revenue')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'category', 'label' => __('Category')],
                ['key' => 'pieces', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'revenue', 'label' => __('Revenue'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'category' => __('Total'),
                'pieces' => $rows->sum('pieces'),
                'revenue' => $rows->sum('revenue'),
            ],
            chart: ReportResult::chart(__('Revenue by category'), 'revenue', $rows->take(10)->map(fn (array $row): array => [
                'label' => $row['category'],
                'value' => $row['revenue'],
                'display' => ReportFormatter::moneyCompact($row['revenue']),
            ])->all()),
            meta: [
                'table_title' => __('Category breakdown'),
                'money' => true,
                'empty_title' => __('No jewellery sold in this period'),
                'empty_message' => __('Loose stone sales are reported separately under Loose Stone Sales.'),
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
            title: __('Sales by Staff'),
            summary: [
                $this->card('sellers', __('Sellers Active'), ReportFormatter::count($rows->count()), 'group'),
                $this->card('invoices', __('Invoices'), ReportFormatter::count($totals['invoice_count']), 'receipt_long'),
                $this->card('revenue', __('Net Revenue'), ReportFormatter::money($totals['net_revenue']), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'staff', 'label' => __('Staff Member')],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'average_sale', 'label' => __('Average Sale'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'net_revenue', 'label' => __('Net Revenue'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'staff' => __('Total'),
                'invoice_count' => $totals['invoice_count'],
                'average_sale' => $totals['average_sale'],
                'net_revenue' => $totals['net_revenue'],
            ],
            chart: ReportResult::chart(__('Revenue by staff member'), 'revenue', $rows->take(10)->map(fn (array $row): array => [
                'label' => $row['staff'],
                'value' => $row['net_revenue'],
                'display' => ReportFormatter::moneyCompact($row['net_revenue']),
            ])->all()),
            meta: ['table_title' => __('Staff breakdown'), 'money' => true],
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
            title: __('Gold Weight Sold'),
            summary: [
                $this->card('net_weight', __('Net Weight Sold'), ReportFormatter::grams($rows->sum('net_weight')), 'scale'),
                $this->card('gross_weight', __('Gross Weight Sold'), ReportFormatter::grams($rows->sum('gross_weight')), 'monitor_weight',
                    hint: __('Includes stone weight')),
                $this->card('pieces', __('Pieces Sold'), ReportFormatter::count($rows->sum('pieces')), 'shopping_bag'),
                $this->card('revenue', __('Revenue'), ReportFormatter::money($rows->sum('revenue')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'purity', 'label' => __('Purity')],
                ['key' => 'pieces', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'gross_weight', 'label' => __('Gross Weight'), 'align' => 'right', 'format' => 'grams'],
                ['key' => 'net_weight', 'label' => __('Net Weight'), 'align' => 'right', 'format' => 'grams', 'emphasis' => 'strong'],
                ['key' => 'revenue', 'label' => __('Revenue'), 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'purity' => __('Total'),
                'pieces' => $rows->sum('pieces'),
                'gross_weight' => $rows->sum('gross_weight'),
                'net_weight' => $rows->sum('net_weight'),
                'revenue' => $rows->sum('revenue'),
            ],
            chart: ReportResult::chart(__('Net weight sold by purity'), 'grams', $rows->map(fn (array $row): array => [
                'label' => $row['purity'],
                'value' => $row['net_weight'],
                'display' => ReportFormatter::grams($row['net_weight']),
            ])->all()),
            meta: ['table_title' => __('Weight sold by purity'), 'money' => true],
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
            title: __('Discount Report'),
            summary: [
                $this->card('discount_total', __('Total Discount'), ReportFormatter::money($totals['discount_total']), 'percent'),
                $this->card('discounted_invoices', __('Discounted Invoices'), ReportFormatter::count($perInvoice->count()), 'receipt_long',
                    hint: __('of :count invoices', ['count' => ReportFormatter::count($totals['invoice_count'])])),
                $this->card('discount_share', __('Discount as % of Gross'),
                    ReportFormatter::percent($totals['gross_sales'] > 0 ? $totals['discount_total'] / $totals['gross_sales'] * 100 : 0),
                    'trending_down'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'invoice_number', 'label' => __('Invoice')],
                ['key' => 'invoice_date', 'label' => __('Date')],
                ['key' => 'customer', 'label' => __('Customer')],
                ['key' => 'applied_by', 'label' => __('Applied By')],
                ['key' => 'gross', 'label' => __('Gross'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'discount_percent', 'label' => __('Discount %'), 'align' => 'right', 'format' => 'percent'],
                ['key' => 'discount', 'label' => __('Discount'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'net', 'label' => __('Net'), 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'invoice_number' => __('Total'),
                'gross' => $perInvoice->sum('gross'),
                'discount' => $perInvoice->sum('discount'),
                'net' => $perInvoice->sum('net'),
            ],
            meta: [
                'table_title' => __('Discounted invoices'),
                'money' => true,
                'empty_title' => __('No discounts given in this period'),
                'empty_message' => __('Every invoice in this range was billed at full price.'),
                // Rendered by the report page as a second table below the first.
                'extra_tables' => [
                    [
                        'title' => __('Discount by staff member'),
                        'columns' => [
                            ['key' => 'staff', 'label' => __('Staff Member')],
                            ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'gross_total', 'label' => __('Gross'), 'align' => 'right', 'format' => 'money'],
                            ['key' => 'discount_percent', 'label' => __('Discount %'), 'align' => 'right', 'format' => 'percent'],
                            ['key' => 'discount_total', 'label' => __('Discount'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $perStaff,
                        'totals' => [
                            'staff' => __('Total'),
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
            title: __('Tax Collected'),
            summary: [
                $this->card('tax_total', __('Tax Collected'), ReportFormatter::money($taxTotal), 'receipt'),
                $this->card('taxable_base', __('Taxable Base'), ReportFormatter::money($base), 'sell',
                    hint: __('Gross less discount')),
                $this->card('recargo_total', __('Recargo Collected'), ReportFormatter::money($recargoTotal), 'add_card',
                    hint: __('Recargo de equivalencia')),
                $this->card('effective_rate', __('Effective Rate'),
                    ReportFormatter::percent($base > 0 ? $taxTotal / $base * 100 : 0), 'percent'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'bucket', 'label' => $filter->grouping->label()],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'taxable_base', 'label' => __('Taxable Base'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'effective_rate', 'label' => __('Rate'), 'align' => 'right', 'format' => 'percent'],
                ['key' => 'tax_total', 'label' => __('IVA'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'recargo_total', 'label' => __('Recargo'), 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'bucket' => __('Total'),
                'invoice_count' => $rows->sum('invoice_count'),
                'taxable_base' => $base,
                'tax_total' => $taxTotal,
                'recargo_total' => $recargoTotal,
            ],
            chart: ReportResult::chart(__('Tax collected by ').strtolower($filter->grouping->label()), 'tax', $rows->map(fn (array $row): array => [
                'label' => $row['bucket'],
                'value' => $row['tax_total'],
                'display' => ReportFormatter::moneyCompact($row['tax_total']),
            ])->all(), 'vertical'),
            meta: [
                'table_title' => __('Tax by period'),
                'money' => true,
                'extra_tables' => [
                    [
                        'title' => __('By rate'),
                        'columns' => [
                            ['key' => 'tax_name', 'label' => __('IVA')],
                            ['key' => 'tax_rate', 'label' => __('Rate'), 'align' => 'right', 'format' => 'percent'],
                            ['key' => 'recargo_name', 'label' => __('Recargo')],
                            ['key' => 'recargo_rate', 'label' => __('Rate'), 'align' => 'right', 'format' => 'percent'],
                            ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'taxable_base', 'label' => __('Taxable Base'), 'align' => 'right', 'format' => 'money'],
                            ['key' => 'tax_total', 'label' => __('IVA'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                            ['key' => 'recargo_total', 'label' => __('Recargo'), 'align' => 'right', 'format' => 'money'],
                        ],
                        'rows' => $byRate,
                        'totals' => [
                            'tax_name' => __('Total'),
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
            title: __('Voided Invoices'),
            summary: [
                $this->card('void_count', __('Voided Invoices'), ReportFormatter::count($totals['count']), 'block'),
                $this->card('void_value', __('Value Reversed'), ReportFormatter::money($totals['value']), 'money_off',
                    hint: __('Excluded from all revenue figures')),
                $this->card('void_rate', __('Void Rate'),
                    ReportFormatter::percent($issued > 0 ? $totals['count'] / $issued * 100 : 0), 'percent',
                    hint: __('of all invoices raised')),
            ],
            rows: $rows,
            columns: [
                ['key' => 'invoice_number', 'label' => __('Invoice')],
                ['key' => 'invoice_date', 'label' => __('Invoice Date')],
                ['key' => 'customer', 'label' => __('Customer')],
                ['key' => 'void_reason', 'label' => __('Reason')],
                ['key' => 'voided_by', 'label' => __('Voided By')],
                ['key' => 'voided_at', 'label' => __('Voided At'), 'format' => 'datetime'],
                ['key' => 'original_value', 'label' => __('Original Value'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'invoice_number' => __('Total'),
                'original_value' => $totals['value'],
            ],
            meta: [
                'table_title' => __('Voided invoices'),
                'money' => true,
                'empty_title' => __('No invoices were voided in this period'),
                'empty_message' => __('Every invoice raised in this range still stands.'),
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
            title: __('Loose Stone Sales'),
            summary: [
                $this->card('quantity', __('Pieces Sold'), ReportFormatter::count($rows->sum('quantity')), 'diamond'),
                $this->card('grams', __('Grams Sold'), ReportFormatter::grams($rows->sum('grams')), 'scale'),
                $this->card('revenue', __('Revenue'), ReportFormatter::money($rows->sum('revenue')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'stone_code', 'label' => __('Code')],
                ['key' => 'stone_name', 'label' => __('Stone')],
                ['key' => 'shape', 'label' => __('Shape')],
                ['key' => 'quantity', 'label' => __('Pieces'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'grams', 'label' => __('Grams'), 'align' => 'right', 'format' => 'grams'],
                ['key' => 'revenue', 'label' => __('Revenue'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'stone_code' => __('Total'),
                'quantity' => $rows->sum('quantity'),
                'grams' => $rows->sum('grams'),
                'revenue' => $rows->sum('revenue'),
            ],
            meta: [
                'table_title' => __('Loose stone sales'),
                'money' => true,
                'empty_title' => __('No loose stones sold in this period'),
                'empty_message' => __('Stones set into finished pieces are counted under Sales by Category.'),
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
