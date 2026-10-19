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
            title: __('Top Customers'),
            summary: [
                $this->card('buyers', __('Customers Who Bought'), ReportFormatter::count($rate['buyers']), 'group'),
                $this->card('spend', __('Attributed Spend'), ReportFormatter::money($rows->sum('total_spent')), 'payments',
                    hint: __('Walk-in sales are not attributable to a customer')),
                $this->card('walk_ins', __('Walk-in Invoices'), ReportFormatter::count($rate['walk_in_invoices']), 'directions_walk'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'customer_code', 'label' => __('Code')],
                ['key' => 'name', 'label' => __('Customer')],
                ['key' => 'phone', 'label' => __('Phone')],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'average_sale', 'label' => __('Average Sale'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'last_purchase', 'label' => __('Last Purchase')],
                ['key' => 'total_spent', 'label' => __('Total Spent'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'customer_code' => __('Total'),
                'invoice_count' => $rows->sum('invoice_count'),
                'total_spent' => $rows->sum('total_spent'),
            ],
            chart: ReportResult::chart(__('Spend by customer'), 'revenue', $rows->take(10)->map(fn (array $row): array => [
                'label' => $row['name'],
                'value' => $row['total_spent'],
                'display' => ReportFormatter::moneyCompact($row['total_spent']),
            ])->all()),
            meta: [
                'table_title' => __('Top customers by spend'),
                'money' => true,
                'empty_title' => __('No attributable customer sales in this period'),
                'empty_message' => __('Sales recorded as walk-ins carry no customer record and cannot be ranked.'),
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
            title: __('Cross-Branch Customers'),
            summary: [
                $this->card('customers', __('Cross-Branch Customers'), ReportFormatter::count($rows->count()), 'hub'),
                $this->card('spend', __('Combined Spend'), ReportFormatter::money($rows->sum('total_spent')), 'payments'),
                $this->card('invoices', __('Combined Invoices'), ReportFormatter::count($rows->sum('invoice_count')), 'receipt_long'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'customer_code', 'label' => __('Code')],
                ['key' => 'name', 'label' => __('Customer')],
                ['key' => 'phone', 'label' => __('Phone')],
                ['key' => 'branch_count', 'label' => __('Branches'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'branches', 'label' => __('Visited')],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'total_spent', 'label' => __('Total Spent'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            meta: [
                'table_title' => __('Customers active at more than one branch'),
                'money' => true,
                'ignores_date_range' => true,
                'empty_title' => __('No customer has purchased at more than one branch'),
                'empty_message' => __('This report covers a customer\'s whole history, not just the selected range.'),
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
            title: __('New Customers'),
            summary: [
                $this->card('new', __('New Customers'), ReportFormatter::count($total), 'person_add',
                    trend: ReportFormatter::trend($total, $previous)),
                $this->card('bought', __('Bought Already'), ReportFormatter::count($converted), 'task_alt',
                    hint: __(':value of new registrations', ['value' => ReportFormatter::percent($rows->count() > 0 ? $converted / $rows->count() * 100 : 0)])),
                $this->card('spend', __('Spend From New Customers'), ReportFormatter::money($rows->sum('total_spent')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'customer_code', 'label' => __('Code')],
                ['key' => 'name', 'label' => __('Customer')],
                ['key' => 'phone', 'label' => __('Phone')],
                ['key' => 'registered_on', 'label' => __('Registered')],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'total_spent', 'label' => __('Total Spent'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'customer_code' => __('Total'),
                'invoice_count' => $rows->sum('invoice_count'),
                'total_spent' => $rows->sum('total_spent'),
            ],
            meta: [
                'table_title' => __('Registered in this period'),
                'money' => true,
                'empty_title' => __('No new customers registered in this period'),
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
            title: __('Repeat Customers'),
            summary: [
                $this->card('repeat', __('Repeat Customers'), ReportFormatter::count($rate['repeat_buyers']), 'repeat'),
                $this->card('rate', __('Repeat Rate'), ReportFormatter::percent($rate['repeat_rate']), 'percent',
                    hint: __('of :count customers who bought', ['count' => ReportFormatter::count($rate['buyers'])])),
                $this->card('spend', __('Spend From Repeat Customers'), ReportFormatter::money($rows->sum('total_spent')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'customer_code', 'label' => __('Code')],
                ['key' => 'name', 'label' => __('Customer')],
                ['key' => 'phone', 'label' => __('Phone')],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'first_purchase', 'label' => __('First')],
                ['key' => 'last_purchase', 'label' => __('Latest')],
                ['key' => 'total_spent', 'label' => __('Total Spent'), 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'customer_code' => __('Total'),
                'invoice_count' => $rows->sum('invoice_count'),
                'total_spent' => $rows->sum('total_spent'),
            ],
            meta: [
                'table_title' => __('Customers with more than one purchase'),
                'money' => true,
                'empty_title' => __('No customer bought twice in this period'),
                'empty_message' => __('A wider date range will usually surface repeat business.'),
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
                title: __('Customer Purchase History'),
                meta: [
                    'empty_title' => __('Choose a customer'),
                    'empty_message' => __('Select a customer in the filter bar to see their purchases across every branch.'),
                    'requires_customer' => true,
                ],
                columns: [['key' => 'invoice_number', 'label' => __('Invoice')]],
            );
        }

        $customerId = (int) $filter->option('customer_id');
        $customer = Customer::query()->whereKey($customerId)->first();
        $lifetime = $this->customers->customerLifetime($customerId);
        $rows = $this->customers->purchaseHistory($customerId, $this->limit());

        return new ReportResult(
            key: 'customer-purchase-history',
            title: __('Customer Purchase History'),
            summary: [
                $this->card('invoices', __('Lifetime Invoices'), ReportFormatter::count($lifetime['invoice_count']), 'receipt_long'),
                $this->card('spent', __('Lifetime Spend'), ReportFormatter::money($lifetime['total_spent']), 'payments'),
                $this->card('average', __('Average Sale'), ReportFormatter::money($lifetime['average_sale']), 'analytics'),
                $this->card('branches', __('Branches Visited'), ReportFormatter::count($lifetime['branch_count']), 'store',
                    hint: __('First purchase :date', ['date' => $lifetime['first_purchase'] ?? '—'])),
            ],
            rows: $rows,
            columns: [
                ['key' => 'invoice_number', 'label' => __('Invoice')],
                ['key' => 'invoice_date', 'label' => __('Date')],
                ['key' => 'branch', 'label' => __('Branch')],
                ['key' => 'payment_method', 'label' => __('Paid By')],
                ['key' => 'grand_total', 'label' => __('Total'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'invoice_number' => __('Total'),
                'grand_total' => $rows->sum('grand_total'),
            ],
            meta: [
                'table_title' => $customer !== null ? __(':name — purchases', ['name' => $customer->name]) : __('Purchases'),
                'money' => true,
                'ignores_date_range' => true,
                'empty_title' => __('This customer has no completed purchases'),
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
