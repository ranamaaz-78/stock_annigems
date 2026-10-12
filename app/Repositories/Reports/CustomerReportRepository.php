<?php

namespace App\Repositories\Reports;

use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Reports\ReportFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * CustomerReportRepository
 *
 * Customer analytics.
 *
 * Customers are deliberately global in this system — the table carries no
 * branch_id and the model does not use BelongsToBranch — because the same
 * person may walk into any branch. That single design decision is what makes
 * the cross-branch report below possible at all.
 *
 * Purchase figures are still branch-scoped where a branch Admin is asking:
 * spend is measured from invoices, and invoices belong to a branch. The
 * cross-branch report is the one deliberate exception, and it reports only
 * which branches a customer visited and what they spent there — it is built
 * from the customer's own purchases, which that customer's branch already sees.
 */
class CustomerReportRepository extends ReportRepository
{
    /**
     * Highest-spending customers in the period.
     *
     * Walk-in sales carry no customer_id and are excluded: they cannot be
     * attributed to a person, and lumping them into one "customer" row would
     * produce a meaningless top entry.
     *
     * @return Collection<int, array{
     *     uuid: string, customer_code: string, name: string, phone: string,
     *     invoice_count: int, total_spent: float, average_sale: float, last_purchase: ?string
     * }>
     */
    public function topCustomers(ReportFilter $filter, int $limit): Collection
    {
        return $this->purchases($filter)
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw('customers.uuid, customers.customer_code, customers.name, customers.phone')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(invoices.grand_total), 0) as total_spent')
            ->selectRaw('MAX(invoices.invoice_date) as last_purchase')
            ->groupBy('customers.uuid', 'customers.customer_code', 'customers.name', 'customers.phone')
            ->orderByRaw('COALESCE(SUM(invoices.grand_total), 0) DESC')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $count = $this->toInt($row->invoice_count);
                $spent = $this->toFloat($row->total_spent);

                return [
                    'uuid' => (string) $row->uuid,
                    'customer_code' => (string) $row->customer_code,
                    'name' => (string) $row->name,
                    'phone' => (string) $row->phone,
                    'invoice_count' => $count,
                    'total_spent' => $spent,
                    'average_sale' => $count > 0 ? $spent / $count : 0.0,
                    'last_purchase' => $row->last_purchase,
                ];
            });
    }

    /**
     * Customers who have purchased at more than one branch.
     *
     * Deliberately not date-filtered on the branch count: a customer who bought
     * at branch A last year and branch B today is exactly the cross-branch
     * customer this report exists to find, and restricting to one month would
     * hide them.
     *
     * @return Collection<int, array{
     *     uuid: string, customer_code: string, name: string, phone: string,
     *     branch_count: int, branches: string, invoice_count: int, total_spent: float
     * }>
     */
    public function crossBranchCustomers(int $limit): Collection
    {
        $rows = $this->unscoped(Invoice::class)->completed()
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->join('branches', 'branches.id', '=', 'invoices.branch_id')
            ->selectRaw('customers.uuid, customers.customer_code, customers.name, customers.phone')
            ->selectRaw('branches.name as branch_name')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(invoices.grand_total), 0) as total_spent')
            ->groupBy('customers.uuid', 'customers.customer_code', 'customers.name', 'customers.phone', 'branches.name')
            ->get();

        // Grouped in PHP rather than with a database-specific string aggregate
        // (GROUP_CONCAT / string_agg), which differs between MySQL and SQLite.
        // The set is one row per customer-branch pair, so it stays small.
        return $rows
            ->groupBy('uuid')
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group): array => [
                'uuid' => (string) $group->first()->uuid,
                'customer_code' => (string) $group->first()->customer_code,
                'name' => (string) $group->first()->name,
                'phone' => (string) $group->first()->phone,
                'branch_count' => $group->count(),
                'branches' => $group->pluck('branch_name')->sort()->implode(', '),
                'invoice_count' => $group->sum(fn ($row): int => $this->toInt($row->invoice_count)),
                'total_spent' => $group->sum(fn ($row): float => $this->toFloat($row->total_spent)),
            ])
            ->sortByDesc('total_spent')
            ->take($limit)
            ->values();
    }

    /**
     * Customers first registered in the period, with their first purchase.
     *
     * Registration is global, so this is not branch-filtered — a branch Admin
     * seeing "12 new customers this month" is seeing the business's new
     * customers, which is the figure that matters for the customer base.
     *
     * @return Collection<int, array{
     *     uuid: string, customer_code: string, name: string, phone: string,
     *     registered_on: string, invoice_count: int, total_spent: float
     * }>
     */
    public function newCustomers(ReportFilter $filter, int $limit): Collection
    {
        return Customer::query()
            ->whereBetween('customers.created_at', [$filter->from, $filter->to])
            ->leftJoin('invoices', function ($join): void {
                $join->on('invoices.customer_id', '=', 'customers.id')
                    ->where('invoices.status', '=', \App\Enums\InvoiceStatus::COMPLETED->value);
            })
            ->selectRaw('customers.uuid, customers.customer_code, customers.name, customers.phone, customers.created_at')
            ->selectRaw('COUNT(invoices.id) as invoice_count')
            ->selectRaw('COALESCE(SUM(invoices.grand_total), 0) as total_spent')
            ->groupBy('customers.uuid', 'customers.customer_code', 'customers.name', 'customers.phone', 'customers.created_at')
            ->orderByDesc('customers.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'customer_code' => (string) $row->customer_code,
                'name' => (string) $row->name,
                'phone' => (string) $row->phone,
                'registered_on' => \Illuminate\Support\Carbon::parse($row->created_at)->toDateString(),
                'invoice_count' => $this->toInt($row->invoice_count),
                'total_spent' => $this->toFloat($row->total_spent),
            ]);
    }

    /**
     * Count of customers registered in the period.
     */
    public function newCustomerCount(ReportFilter $filter): int
    {
        return Customer::query()
            ->whereBetween('created_at', [$filter->from, $filter->to])
            ->count();
    }

    /**
     * Customers with more than one completed invoice in the period.
     *
     * @return Collection<int, array{
     *     uuid: string, customer_code: string, name: string, phone: string,
     *     invoice_count: int, total_spent: float, average_sale: float,
     *     first_purchase: ?string, last_purchase: ?string
     * }>
     */
    public function repeatCustomers(ReportFilter $filter, int $limit): Collection
    {
        return $this->purchases($filter)
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw('customers.uuid, customers.customer_code, customers.name, customers.phone')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(invoices.grand_total), 0) as total_spent')
            ->selectRaw('MIN(invoices.invoice_date) as first_purchase')
            ->selectRaw('MAX(invoices.invoice_date) as last_purchase')
            ->groupBy('customers.uuid', 'customers.customer_code', 'customers.name', 'customers.phone')
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $count = $this->toInt($row->invoice_count);
                $spent = $this->toFloat($row->total_spent);

                return [
                    'uuid' => (string) $row->uuid,
                    'customer_code' => (string) $row->customer_code,
                    'name' => (string) $row->name,
                    'phone' => (string) $row->phone,
                    'invoice_count' => $count,
                    'total_spent' => $spent,
                    'average_sale' => $count > 0 ? $spent / $count : 0.0,
                    'first_purchase' => $row->first_purchase,
                    'last_purchase' => $row->last_purchase,
                ];
            });
    }

    /**
     * How many distinct customers bought, and how many of them bought twice.
     *
     * Counted with a sub-query so the repeat rate is a single figure computed
     * in SQL rather than by pulling every customer into memory.
     *
     * @return array{buyers: int, repeat_buyers: int, repeat_rate: float, walk_in_invoices: int}
     */
    public function repeatRate(ReportFilter $filter): array
    {
        $buyers = (clone $this->purchases($filter))
            ->distinct()
            ->count('invoices.customer_id');

        $repeatBuyers = $this->purchases($filter)
            ->select('invoices.customer_id')
            ->groupBy('invoices.customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        // Walk-in sales are counted separately rather than ignored: a branch
        // where most sales are walk-ins has a low repeat rate for a reason that
        // is not poor retention.
        $walkIns = $this->completed($filter)->whereNull('invoices.customer_id')->count();

        return [
            'buyers' => $buyers,
            'repeat_buyers' => $repeatBuyers,
            'repeat_rate' => $buyers > 0 ? $repeatBuyers / $buyers * 100 : 0.0,
            'walk_in_invoices' => $walkIns,
        ];
    }

    /**
     * One customer's purchases, with the branch named on every row.
     *
     * Deliberately not branch-scoped. The customer is global, and a branch
     * Admin looking at a customer's history needs to know the customer also
     * shops elsewhere — otherwise the history is misleading. Only aggregate
     * invoice-level facts appear; no line items are exposed.
     *
     * @return Collection<int, array{
     *     invoice_number: string, invoice_date: string, branch: string,
     *     payment_method: string, grand_total: float
     * }>
     */
    public function purchaseHistory(int $customerId, int $limit): Collection
    {
        return $this->unscoped(Invoice::class)->completed()
            ->where('invoices.customer_id', $customerId)
            ->join('branches', 'branches.id', '=', 'invoices.branch_id')
            ->orderByDesc('invoices.invoice_date')
            ->orderByDesc('invoices.id')
            ->limit($limit)
            ->get([
                'invoices.invoice_number',
                'invoices.invoice_date',
                'invoices.payment_method',
                'invoices.grand_total',
                'branches.name as branch_name',
            ])
            ->map(fn ($row): array => [
                'invoice_number' => (string) $row->invoice_number,
                'invoice_date' => \Illuminate\Support\Carbon::parse($row->invoice_date)->toDateString(),
                'branch' => (string) $row->branch_name,
                'payment_method' => \App\Enums\PaymentMethod::tryFrom((string) $row->payment_method)?->label() ?? (string) $row->payment_method,
                'grand_total' => $this->toFloat($row->grand_total),
            ]);
    }

    /**
     * Lifetime totals for one customer, across every branch.
     *
     * @return array{invoice_count: int, total_spent: float, average_sale: float, branch_count: int, first_purchase: ?string, last_purchase: ?string}
     */
    public function customerLifetime(int $customerId): array
    {
        $row = $this->unscoped(Invoice::class)->completed()
            ->where('customer_id', $customerId)
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total_spent')
            ->selectRaw('COUNT(DISTINCT branch_id) as branch_count')
            ->selectRaw('MIN(invoice_date) as first_purchase')
            ->selectRaw('MAX(invoice_date) as last_purchase')
            ->first();

        $count = $this->toInt($row?->invoice_count);
        $spent = $this->toFloat($row?->total_spent);

        return [
            'invoice_count' => $count,
            'total_spent' => $spent,
            'average_sale' => $count > 0 ? $spent / $count : 0.0,
            'branch_count' => $this->toInt($row?->branch_count),
            'first_purchase' => $row?->first_purchase,
            'last_purchase' => $row?->last_purchase,
        ];
    }

    /**
     * Completed invoices in range for the filter's branch.
     */
    private function completed(ReportFilter $filter): Builder
    {
        $query = $this->unscoped(Invoice::class)->completed();

        $this->scopeToBranch($query, $filter->branchId, 'invoices');
        $this->scopeToDateRange($query, $filter, 'invoices.invoice_date');

        return $query;
    }

    /**
     * Completed invoices in range that are attributable to a customer record.
     */
    private function purchases(ReportFilter $filter): Builder
    {
        return $this->completed($filter)->whereNotNull('invoices.customer_id');
    }
}
