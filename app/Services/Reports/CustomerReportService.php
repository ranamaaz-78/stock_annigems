<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Repositories\Reports\CustomerReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * CustomerReportService
 *
 * Builds the Admin customer reports.
 *
 * Customers are global in this system — the table has no branch_id — because
 * the same person may shop at any branch. Spend figures are still measured from
 * invoices, which do belong to a branch, so a branch Admin sees what a customer
 * spent with them. The cross-branch and purchase-history reports are the
 * deliberate exceptions: they name the other branches a customer visited,
 * because a customer history that hides half the purchases is worse than none.
 */
class CustomerReportService
{
    public function __construct(
        private CustomerReportRepository $customers,
    ) {}

    /**
     * Highest-spending customers in the period.
     */
    public function topCustomers(ReportFilter $filter): ReportResult
    {
        $rows = $this->customers->topCustomers($filter, $this->limit());
        $rate = $this->customers->repeatRate($filter);

        return new ReportResult(
            key: 'top-customers',
            title: 'Top Customers',
            summary: [
                $this->card('buyers', 'Customers Who Bought', ReportFormatter::count($rate['buyers']), 'group'),
                $this->card('spend', 'Attributed Spend', ReportFormatter::money($rows->sum('total_spent')), 'payments',
                    hint: 'Walk-in sales are not attributable to a customer'),
                $this->card('walk_ins', 'Walk-in Invoices', ReportFormatter::count($rate['walk_in_invoices']), 'directions_walk'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'customer_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Customer'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'average_sale', 'label' => 'Average Sale', 'align' => 'right', 'format' => 'money'],
                ['key' => 'last_purchase', 'label' => 'Last Purchase'],
                ['key' => 'total_spent', 'label' => 'Total Spent', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'customer_code' => 'Total',
                'invoice_count' => $rows->sum('invoice_count'),
                'total_spent' => $rows->sum('total_spent'),
            ],
            chart: ReportResult::chart('Spend by customer', 'revenue', $rows->take(10)->map(fn (array $row): array => [
                'label' => $row['name'],
                'value' => $row['total_spent'],
                'display' => ReportFormatter::moneyCompact($row['total_spent']),
            ])->all()),
            meta: [
                'table_title' => 'Top customers by spend',
                'money' => true,
                'empty_title' => 'No attributable customer sales in this period',
                'empty_message' => 'Sales recorded as walk-ins carry no customer record and cannot be ranked.',
            ],
        );
    }

    /**
     * Customers who have purchased at more than one branch.
     *
     * Not narrowed by the date range: a customer who bought at one branch last
     * year and another today is precisely who this report exists to surface.
     */
    public function crossBranch(ReportFilter $filter): ReportResult
    {
        $rows = $this->customers->crossBranchCustomers($this->limit());

        return new ReportResult(
            key: 'cross-branch-customers',
            title: 'Cross-Branch Customers',
            summary: [
                $this->card('customers', 'Cross-Branch Customers', ReportFormatter::count($rows->count()), 'hub'),
                $this->card('spend', 'Combined Spend', ReportFormatter::money($rows->sum('total_spent')), 'payments'),
                $this->card('invoices', 'Combined Invoices', ReportFormatter::count($rows->sum('invoice_count')), 'receipt_long'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'customer_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Customer'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'branch_count', 'label' => 'Branches', 'align' => 'right', 'format' => 'count'],
                ['key' => 'branches', 'label' => 'Visited'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'total_spent', 'label' => 'Total Spent', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            meta: [
                'table_title' => 'Customers active at more than one branch',
                'money' => true,
                'ignores_date_range' => true,
                'empty_title' => 'No customer has purchased at more than one branch',
                'empty_message' => 'This report covers a customer\'s whole history, not just the selected range.',
            ],
        );
    }

    /**
     * Customers first registered in the period.
     */
    public function newCustomers(ReportFilter $filter): ReportResult
    {
        $rows = $this->customers->newCustomers($filter, $this->limit());
        $total = $this->customers->newCustomerCount($filter);
        $previous = $this->customers->newCustomerCount($filter->previousPeriod());
        $converted = $rows->where('invoice_count', '>', 0)->count();

        return new ReportResult(
            key: 'new-customers',
            title: 'New Customers',
            summary: [
                $this->card('new', 'New Customers', ReportFormatter::count($total), 'person_add',
                    trend: ReportFormatter::trend($total, $previous)),
                $this->card('bought', 'Bought Already', ReportFormatter::count($converted), 'task_alt',
                    hint: ReportFormatter::percent($rows->count() > 0 ? $converted / $rows->count() * 100 : 0).' of new registrations'),
                $this->card('spend', 'Spend From New Customers', ReportFormatter::money($rows->sum('total_spent')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'customer_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Customer'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'registered_on', 'label' => 'Registered'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count'],
                ['key' => 'total_spent', 'label' => 'Total Spent', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'customer_code' => 'Total',
                'invoice_count' => $rows->sum('invoice_count'),
                'total_spent' => $rows->sum('total_spent'),
            ],
            meta: [
                'table_title' => 'Registered in this period',
                'money' => true,
                'empty_title' => 'No new customers registered in this period',
            ],
        );
    }

    /**
     * Customers who came back, and the repeat rate.
     */
    public function repeatCustomers(ReportFilter $filter): ReportResult
    {
        $rows = $this->customers->repeatCustomers($filter, $this->limit());
        $rate = $this->customers->repeatRate($filter);

        return new ReportResult(
            key: 'repeat-customers',
            title: 'Repeat Customers',
            summary: [
                $this->card('repeat', 'Repeat Customers', ReportFormatter::count($rate['repeat_buyers']), 'repeat'),
                $this->card('rate', 'Repeat Rate', ReportFormatter::percent($rate['repeat_rate']), 'percent',
                    hint: 'of '.ReportFormatter::count($rate['buyers']).' customers who bought'),
                $this->card('spend', 'Spend From Repeat Customers', ReportFormatter::money($rows->sum('total_spent')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'customer_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Customer'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'first_purchase', 'label' => 'First'],
                ['key' => 'last_purchase', 'label' => 'Latest'],
                ['key' => 'total_spent', 'label' => 'Total Spent', 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'customer_code' => 'Total',
                'invoice_count' => $rows->sum('invoice_count'),
                'total_spent' => $rows->sum('total_spent'),
            ],
            meta: [
                'table_title' => 'Customers with more than one purchase',
                'money' => true,
                'empty_title' => 'No customer bought twice in this period',
                'empty_message' => 'A wider date range will usually surface repeat business.',
            ],
        );
    }

    /**
     * One customer's purchases across every branch.
     *
     * Requires the customer filter; without it the report explains what to do
     * rather than dumping every purchase in the system.
     */
    public function purchaseHistory(ReportFilter $filter): ReportResult
    {
        if (! $filter->hasOption('customer_id')) {
            return new ReportResult(
                key: 'customer-purchase-history',
                title: 'Customer Purchase History',
                meta: [
                    'empty_title' => 'Choose a customer',
                    'empty_message' => 'Select a customer in the filter bar to see their purchases across every branch.',
                    'requires_customer' => true,
                ],
                columns: [['key' => 'invoice_number', 'label' => 'Invoice']],
            );
        }

        $customerId = (int) $filter->option('customer_id');
        $customer = Customer::query()->whereKey($customerId)->first();
        $lifetime = $this->customers->customerLifetime($customerId);
        $rows = $this->customers->purchaseHistory($customerId, $this->limit());

        return new ReportResult(
            key: 'customer-purchase-history',
            title: 'Customer Purchase History',
            summary: [
                $this->card('invoices', 'Lifetime Invoices', ReportFormatter::count($lifetime['invoice_count']), 'receipt_long'),
                $this->card('spent', 'Lifetime Spend', ReportFormatter::money($lifetime['total_spent']), 'payments'),
                $this->card('average', 'Average Sale', ReportFormatter::money($lifetime['average_sale']), 'analytics'),
                $this->card('branches', 'Branches Visited', ReportFormatter::count($lifetime['branch_count']), 'store',
                    hint: 'First purchase '.($lifetime['first_purchase'] ?? '—')),
            ],
            rows: $rows,
            columns: [
                ['key' => 'invoice_number', 'label' => 'Invoice'],
                ['key' => 'invoice_date', 'label' => 'Date'],
                ['key' => 'branch', 'label' => 'Branch'],
                ['key' => 'payment_method', 'label' => 'Paid By'],
                ['key' => 'grand_total', 'label' => 'Total', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'invoice_number' => 'Total',
                'grand_total' => $rows->sum('grand_total'),
            ],
            meta: [
                'table_title' => $customer !== null ? $customer->name.' — purchases' : 'Purchases',
                'money' => true,
                'ignores_date_range' => true,
                'empty_title' => 'This customer has no completed purchases',
            ],
        );
    }

    /**
     * Row cap shared by every customer listing.
     */
    private function limit(): int
    {
        return (int) config('annigems.reports.per_page', 100);
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
