<?php

namespace App\Services\Reports\SuperAdmin;

use App\Repositories\Reports\CustomerReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * AggregateCustomerReportService
 *
 * SuperAdmin customer reporting.
 *
 * A customer is a person, not a branch transaction, and the top-customer and
 * cross-branch views are inherently about individuals — so unlike the other
 * families this one does name customers. What it never exposes is a purchase:
 * there is no purchase-history method here, no invoice number appears in any
 * column, and contact details are omitted. The SuperAdmin sees who the
 * business's largest customers are, not what any of them bought.
 */
class AggregateCustomerReportService extends AggregateReportService
{
    public function __construct(
        private CustomerReportRepository $customers,
    ) {}

    /**
     * Highest-spending customers across the selected scope.
     */
    public function topCustomers(ReportFilter $filter): ReportResult
    {
        $rows = $this->customers->topCustomers($filter, $this->limit())
            ->map(fn (array $row): array => [
                'name' => $row['name'],
                'invoice_count' => $row['invoice_count'],
                'average_sale' => $row['average_sale'],
                'total_spent' => $row['total_spent'],
            ]);

        $rate = $this->customers->repeatRate($filter);

        return new ReportResult(
            key: 'top-customers',
            title: __('Top Customers'),
            summary: [
                $this->card('buyers', __('Customers Who Bought'), ReportFormatter::count($rate['buyers']), 'group'),
                $this->card('spend', __('Attributed Spend'), ReportFormatter::money($rows->sum('total_spent')), 'payments'),
                $this->card('walk_ins', __('Walk-in Invoices'), ReportFormatter::count($rate['walk_in_invoices']), 'directions_walk'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'name', 'label' => __('Customer')],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'average_sale', 'label' => __('Average Sale'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'total_spent', 'label' => __('Total Spent'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'name' => __('Total'),
                'invoice_count' => $rows->sum('invoice_count'),
                'total_spent' => $rows->sum('total_spent'),
            ],
            chart: ReportResult::chart(__('Spend by customer'), 'revenue', $rows->take(10)->map(fn (array $row): array => [
                'label' => $row['name'],
                'value' => $row['total_spent'],
                'display' => ReportFormatter::moneyCompact($row['total_spent']),
            ])->all()),
            meta: $this->meta(['table_title' => __('Top customers by spend'), 'money' => true]),
        );
    }

    /**
     * Customers who shop at more than one branch.
     *
     * The report that most justifies customers being global: only an actor
     * looking across branches can act on it.
     */
    public function crossBranch(ReportFilter $filter): ReportResult
    {
        $rows = $this->customers->crossBranchCustomers($this->limit())
            ->map(fn (array $row): array => [
                'name' => $row['name'],
                'branch_count' => $row['branch_count'],
                'branches' => $row['branches'],
                'invoice_count' => $row['invoice_count'],
                'total_spent' => $row['total_spent'],
            ]);

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
                ['key' => 'name', 'label' => __('Customer')],
                ['key' => 'branch_count', 'label' => __('Branches'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'branches', 'label' => __('Visited')],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'total_spent', 'label' => __('Total Spent'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            meta: $this->meta([
                'table_title' => __('Customers active at more than one branch'),
                'money' => true,
                'ignores_date_range' => true,
                'empty_title' => __('No customer has purchased at more than one branch'),
            ]),
        );
    }

    /**
     * New customer registrations, and how many of them have already bought.
     */
    public function newCustomers(ReportFilter $filter): ReportResult
    {
        $rows = $this->customers->newCustomers($filter, $this->limit())
            ->map(fn (array $row): array => [
                'name' => $row['name'],
                'registered_on' => $row['registered_on'],
                'invoice_count' => $row['invoice_count'],
                'total_spent' => $row['total_spent'],
            ]);

        $total = $this->customers->newCustomerCount($filter);
        $previous = $this->customers->newCustomerCount($filter->previousPeriod());
        $converted = $rows->where('invoice_count', '>', 0)->count();

        return new ReportResult(
            key: 'new-customers',
            title: __('New Customers'),
            summary: [
                $this->card('new', __('New Customers'), ReportFormatter::count($total), 'person_add',
                    trend: ReportFormatter::trend($total, $previous)),
                $this->card('bought', __('Bought Already'), ReportFormatter::count($converted), 'task_alt'),
                $this->card('spend', __('Spend From New Customers'), ReportFormatter::money($rows->sum('total_spent')), 'payments'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'name', 'label' => __('Customer')],
                ['key' => 'registered_on', 'label' => __('Registered')],
                ['key' => 'invoice_count', 'label' => __('Invoices'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'total_spent', 'label' => __('Total Spent'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'name' => __('Total'),
                'invoice_count' => $rows->sum('invoice_count'),
                'total_spent' => $rows->sum('total_spent'),
            ],
            meta: $this->meta([
                'table_title' => __('Registered in this period'),
                'money' => true,
                'empty_title' => __('No new customers registered in this period'),
            ]),
        );
    }

    /**
     * Retention per branch: how much of each branch's business comes back.
     */
    public function repeatCustomers(ReportFilter $filter): ReportResult
    {
        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $rate = $this->customers->repeatRate($scoped);

            return [
                'buyers' => $rate['buyers'],
                'repeat_buyers' => $rate['repeat_buyers'],
                'repeat_rate' => $rate['repeat_rate'],
                'walk_in_invoices' => $rate['walk_in_invoices'],
            ];
        });

        $overall = $this->customers->repeatRate($filter);

        return new ReportResult(
            key: 'repeat-customers',
            title: __('Repeat Customers'),
            summary: [
                $this->card('repeat', __('Repeat Customers'), ReportFormatter::count($overall['repeat_buyers']), 'repeat'),
                $this->card('rate', __('Repeat Rate'), ReportFormatter::percent($overall['repeat_rate']), 'percent',
                    hint: __('of :count customers who bought', ['count' => ReportFormatter::count($overall['buyers'])])),
                $this->card('walk_ins', __('Walk-in Invoices'), ReportFormatter::count($overall['walk_in_invoices']), 'directions_walk',
                    hint: __('Not attributable, so not counted in the rate')),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'buyers', 'label' => __('Customers'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'repeat_buyers', 'label' => __('Returned'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'repeat_rate', 'label' => __('Repeat Rate'), 'align' => 'right', 'format' => 'percent', 'emphasis' => 'strong'],
                ['key' => 'walk_in_invoices', 'label' => __('Walk-ins'), 'align' => 'right', 'format' => 'count'],
            ],
            totals: [
                'branch' => 'All Branches',
                'buyers' => $rows->sum('buyers'),
                'repeat_buyers' => $rows->sum('repeat_buyers'),
                'walk_in_invoices' => $rows->sum('walk_in_invoices'),
            ],
            meta: $this->meta([
                'table_title' => __('Retention by branch'),
                'empty_title' => __('No customer purchases in this period'),
            ]),
        );
    }

    /**
     * Row cap shared by every customer listing.
     */
    private function limit(): int
    {
        return (int) config('annigems.reports.top_list_limit', 25);
    }
}
