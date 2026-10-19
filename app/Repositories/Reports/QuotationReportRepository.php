<?php

namespace App\Repositories\Reports;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Support\Reports\ReportFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * QuotationReportRepository
 *
 * Quotation funnel reporting: how much quoted business actually closes.
 *
 * Quotations are not revenue. A quoted value is an offer, and only a completed
 * invoice is money. Nothing in this class feeds a revenue figure — the funnel
 * value columns are labelled as quoted value throughout.
 */
class QuotationReportRepository extends ReportRepository
{
    /**
     * Count and quoted value per status, including statuses with no rows.
     *
     * @return Collection<int, array{status: string, label: string, quotations: int, value: float, share: float}>
     */
    public function funnel(ReportFilter $filter): Collection
    {
        $rows = $this->quotations($filter)
            ->selectRaw('status')
            ->selectRaw('COUNT(*) as quotations')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as value')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row): string => $this->scalar($row->status));

        $total = $rows->sum(fn ($row): int => $this->toInt($row->quotations));

        return collect(QuotationStatus::cases())
            ->map(function (QuotationStatus $status) use ($rows, $total): array {
                $row = $rows->get($status->value);
                $count = $this->toInt($row?->quotations);

                return [
                    'status' => $status->value,
                    'label' => $status->label(),
                    'quotations' => $count,
                    'value' => $this->toFloat($row?->value),
                    'share' => $total > 0 ? $count / $total * 100 : 0.0,
                ];
            })
            ->values();
    }

    /**
     * Funnel headline figures including the conversion rate.
     *
     * Conversion rate is converted divided by total quotations raised in the
     * period. Guarded against a zero denominator so a fresh branch shows 0%
     * rather than breaking.
     *
     * @return array{total: int, draft: int, converted: int, cancelled: int, total_value: float, converted_value: float, conversion_rate: float}
     */
    public function funnelTotals(ReportFilter $filter): array
    {
        $row = $this->quotations($filter)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total_value')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as draft', [QuotationStatus::DRAFT->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as converted', [QuotationStatus::CONVERTED->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled', [QuotationStatus::CANCELLED->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN grand_total ELSE 0 END), 0) as converted_value', [QuotationStatus::CONVERTED->value])
            ->first();

        $total = $this->toInt($row?->total);
        $converted = $this->toInt($row?->converted);

        return [
            'total' => $total,
            'draft' => $this->toInt($row?->draft),
            'converted' => $converted,
            'cancelled' => $this->toInt($row?->cancelled),
            'total_value' => $this->toFloat($row?->total_value),
            'converted_value' => $this->toFloat($row?->converted_value),
            'conversion_rate' => $total > 0 ? $converted / $total * 100 : 0.0,
        ];
    }

    /**
     * Quotations still in draft, oldest first, with age in days.
     *
     * Age matters because an old draft was priced at a stale gold rate; it
     * needs chasing or recalculating before it can be honoured.
     *
     * @return Collection<int, array{uuid: string, quotation_number: string, quotation_date: string, customer: string, value: float, age_days: int}>
     */
    public function pendingDrafts(?int $branchId, int $limit): Collection
    {
        $query = $this->unscoped(Quotation::class)
            ->where('quotations.status', QuotationStatus::DRAFT->value)
            ->leftJoin('customers', 'customers.id', '=', 'quotations.customer_id');

        $this->scopeToBranch($query, $branchId, 'quotations');

        return $query
            ->orderBy('quotations.quotation_date')
            ->limit($limit)
            ->get([
                'quotations.uuid',
                'quotations.quotation_number',
                'quotations.quotation_date',
                'quotations.grand_total',
                'quotations.walkin_name',
                'customers.name as customer_name',
            ])
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'quotation_number' => (string) $row->quotation_number,
                'quotation_date' => (string) $row->quotation_date,
                // A quotation is either against a customer record or a walk-in.
                'customer' => (string) ($row->customer_name ?: $row->walkin_name ?: __('Walk-in')),
                'value' => (float) $row->grand_total,
                'age_days' => (int) \Illuminate\Support\Carbon::parse($row->quotation_date)->diffInDays(now()),
            ]);
    }

    /**
     * Count and total quoted value of all outstanding drafts for a branch.
     *
     * Not date-filtered: an Admin needs to know the whole outstanding pipeline,
     * not just the drafts raised this month.
     *
     * @return array{count: int, value: float}
     */
    public function pendingTotals(?int $branchId, ?int $createdBy = null): array
    {
        $query = $this->unscoped(Quotation::class)
            ->where('status', QuotationStatus::DRAFT->value);

        $this->scopeToBranch($query, $branchId, 'quotations');

        // The Cashier dashboard shows only that cashier's own drafts.
        if ($createdBy !== null) {
            $query->where('created_by', $createdBy);
        }

        $row = $query
            ->selectRaw('COUNT(*) as pending_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as pending_value')
            ->first();

        return [
            'count' => $this->toInt($row?->pending_count),
            'value' => $this->toFloat($row?->pending_value),
        ];
    }

    /**
     * Days between raising a quotation and converting it.
     *
     * There is no converted_at column on quotations, so the conversion moment
     * is taken from the linked invoice's created_at. Rows are returned
     * individually because the median cannot be computed portably in SQL — but
     * the set is bounded by the date filter and one row per converted
     * quotation, which is a small, safe result set.
     *
     * @return Collection<int, array{quotation_number: string, invoice_number: string, days: float}>
     */
    public function timeToConvertRows(ReportFilter $filter): Collection
    {
        $query = $this->unscoped(Quotation::class)
            ->where('quotations.status', QuotationStatus::CONVERTED->value)
            ->whereNotNull('quotations.converted_invoice_id')
            ->join('invoices', 'invoices.id', '=', 'quotations.converted_invoice_id');

        $this->scopeToBranch($query, $filter->branchId, 'quotations');
        $this->scopeToDateRange($query, $filter, 'quotations.quotation_date');

        return $query
            ->orderBy('quotations.created_at')
            ->get([
                'quotations.quotation_number',
                'quotations.created_at as quoted_at',
                'invoices.invoice_number',
                'invoices.created_at as converted_at',
            ])
            ->map(function ($row): array {
                $quoted = \Illuminate\Support\Carbon::parse($row->quoted_at);
                $converted = \Illuminate\Support\Carbon::parse($row->converted_at);

                return [
                    'quotation_number' => (string) $row->quotation_number,
                    'invoice_number' => (string) $row->invoice_number,
                    // Fractional days: a same-day conversion is meaningful
                    // information and should not round to zero.
                    'days' => round($quoted->diffInHours($converted) / 24, 2),
                ];
            });
    }

    /**
     * Average and median days to convert.
     *
     * @return array{count: int, average_days: float, median_days: float, fastest_days: float, slowest_days: float}
     */
    public function timeToConvertSummary(ReportFilter $filter): array
    {
        $days = $this->timeToConvertRows($filter)
            ->pluck('days')
            ->sort()
            ->values();

        if ($days->isEmpty()) {
            return [
                'count' => 0,
                'average_days' => 0.0,
                'median_days' => 0.0,
                'fastest_days' => 0.0,
                'slowest_days' => 0.0,
            ];
        }

        $count = $days->count();
        $middle = (int) floor($count / 2);

        return [
            'count' => $count,
            'average_days' => round((float) $days->avg(), 2),
            // Even-sized sets take the mean of the two central values.
            'median_days' => $count % 2 === 0
                ? round(((float) $days[$middle - 1] + (float) $days[$middle]) / 2, 2)
                : round((float) $days[$middle], 2),
            'fastest_days' => (float) $days->first(),
            'slowest_days' => (float) $days->last(),
        ];
    }

    /**
     * Cancelled quotations with their value and who cancelled them.
     *
     * Cancellation is recorded through the standard attribution columns, so
     * updated_by/updated_by_role identify the actor.
     *
     * @return Collection<int, array{
     *     uuid: string, quotation_number: string, quotation_date: string,
     *     customer: string, value: float, actor_name: ?string, actor_role: ?string,
     *     actor_status: ?string, actor_deleted_at: ?string, cancelled_at: ?string
     * }>
     */
    public function cancelledQuotations(ReportFilter $filter): Collection
    {
        $query = $this->unscoped(Quotation::class)
            ->where('quotations.status', QuotationStatus::CANCELLED->value)
            ->leftJoin('customers', 'customers.id', '=', 'quotations.customer_id')
            ->leftJoin('users', 'users.id', '=', 'quotations.updated_by');

        $this->scopeToBranch($query, $filter->branchId, 'quotations');
        $this->scopeToDateRange($query, $filter, 'quotations.quotation_date');

        return $query
            ->orderByDesc('quotations.updated_at')
            ->get([
                'quotations.uuid',
                'quotations.quotation_number',
                'quotations.quotation_date',
                'quotations.grand_total',
                'quotations.walkin_name',
                'quotations.updated_at as cancelled_at',
                'quotations.updated_by_role as actor_role',
                'customers.name as customer_name',
                'users.name as actor_name',
                'users.status as actor_status',
                'users.deleted_at as actor_deleted_at',
            ])
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'quotation_number' => (string) $row->quotation_number,
                'quotation_date' => (string) $row->quotation_date,
                'customer' => (string) ($row->customer_name ?: $row->walkin_name ?: __('Walk-in')),
                'value' => (float) $row->grand_total,
                'actor_name' => $row->actor_name,
                'actor_role' => $row->actor_role,
                'actor_status' => $row->actor_status,
                'actor_deleted_at' => $row->actor_deleted_at,
                'cancelled_at' => $row->cancelled_at,
            ]);
    }

    /**
     * Base quotation query for the filter's branch, range and options.
     */
    private function quotations(ReportFilter $filter): Builder
    {
        $query = $this->unscoped(Quotation::class);

        $this->scopeToBranch($query, $filter->branchId, 'quotations');
        $this->scopeToDateRange($query, $filter, 'quotations.quotation_date');

        if ($filter->hasOption('quotation_status')) {
            $query->where('quotations.status', $filter->option('quotation_status'));
        }

        if ($filter->hasOption('staff_id')) {
            $query->where('quotations.created_by', $filter->option('staff_id'));
        }

        if ($filter->hasOption('customer_id')) {
            $query->where('quotations.customer_id', $filter->option('customer_id'));
        }

        return $query;
    }
}
