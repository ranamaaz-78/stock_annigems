<?php

namespace App\Repositories\Reports;

use App\Enums\LineType;
use App\Models\Invoice;
use App\Support\Reports\ReportFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SalesReportRepository
 *
 * Every sales figure in the system originates here — dashboard cards and report
 * tables alike — so a card and its report can never disagree.
 *
 * Two rules are enforced structurally rather than by convention:
 *
 * 1. All revenue reads go through Invoice::completed(). A voided invoice is a
 *    reversed sale; its stock returned and its money was not kept. The only
 *    method that sees voided rows is voidedInvoices(), which is a separate
 *    report by design.
 *
 * 2. Line-level reporting reads the *_snapshot columns on invoice_items and
 *    never joins to jewellery_items or stones. A piece can be renamed, its
 *    weight corrected, or its category changed after it was sold; the sale must
 *    keep reporting what was actually sold on the day.
 */
class SalesReportRepository extends ReportRepository
{
    /**
     * Base query: completed invoices for the filter's branch and date range.
     *
     * Every other method in this class starts from here, which is what makes
     * the voided-invoice exclusion impossible to forget.
     */
    public function completedInvoices(ReportFilter $filter): Builder
    {
        $query = $this->unscoped(Invoice::class)->completed();

        $this->scopeToBranch($query, $filter->branchId, 'invoices');
        $this->scopeToDateRange($query, $filter, 'invoices.invoice_date');

        $this->applyOptionalFilters($query, $filter);

        return $query;
    }

    /**
     * Headline totals for a period.
     *
     * gross_sales is the sum of line subtotals before discount; net_revenue is
     * the grand total actually charged. Both are revenue, never profit.
     *
     * @return array{
     *     invoice_count: int,
     *     gross_sales: float,
     *     discount_total: float,
     *     tax_total: float,
     *     net_revenue: float,
     *     average_sale: float
     * }
     */
    public function totals(ReportFilter $filter): array
    {
        $row = $this->completedInvoices($filter)
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discount_total')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as tax_total')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as net_revenue')
            ->first();

        $count = $this->toInt($row?->invoice_count);
        $net = $this->toFloat($row?->net_revenue);

        return [
            'invoice_count' => $count,
            'gross_sales' => $this->toFloat($row?->gross_sales),
            'discount_total' => $this->toFloat($row?->discount_total),
            'tax_total' => $this->toFloat($row?->tax_total),
            'net_revenue' => $net,
            // Average transaction value. Guarded because a fresh branch has no
            // invoices and dividing by zero would break the whole dashboard.
            'average_sale' => $count > 0 ? $net / $count : 0.0,
        ];
    }

    /**
     * Revenue grouped into the filter's time bucket, oldest first.
     *
     * The bucket expression comes from the ReportGrouping enum, never from the
     * request, so no user input reaches the SQL.
     *
     * @return Collection<int, array{bucket: string, invoice_count: int, net_revenue: float}>
     */
    public function revenueTrend(ReportFilter $filter): Collection
    {
        $expression = $filter->grouping->sqlExpression('invoices.invoice_date');

        return $this->completedInvoices($filter)
            ->selectRaw("{$expression} as bucket")
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as net_revenue')
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->get()
            ->map(fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'invoice_count' => $this->toInt($row->invoice_count),
                'net_revenue' => $this->toFloat($row->net_revenue),
            ]);
    }

    /**
     * Revenue split by payment method, with each method's share of the total.
     *
     * @return Collection<int, array{payment_method: string, invoice_count: int, net_revenue: float, share: float}>
     */
    public function byPaymentMethod(ReportFilter $filter): Collection
    {
        $rows = $this->completedInvoices($filter)
            ->selectRaw('payment_method')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as net_revenue')
            ->groupBy('payment_method')
            ->orderByRaw('COALESCE(SUM(grand_total), 0) DESC')
            ->get();

        $total = (float) $rows->sum(fn ($row): float => $this->toFloat($row->net_revenue));

        return $rows->map(fn ($row): array => [
            'payment_method' => $this->scalar($row->payment_method),
            'invoice_count' => $this->toInt($row->invoice_count),
            'net_revenue' => $this->toFloat($row->net_revenue),
            'share' => $total > 0 ? $this->toFloat($row->net_revenue) / $total * 100 : 0.0,
        ]);
    }

    /**
     * Revenue and piece count by jewellery category.
     *
     * Reads category_snapshot, so a category renamed or reassigned after the
     * sale does not rewrite history. Loose stone lines have no category and are
     * grouped under a clear label rather than silently dropped.
     *
     * @return Collection<int, array{category: string, pieces: int, revenue: float}>
     */
    public function byCategory(ReportFilter $filter): Collection
    {
        return $this->lineQuery($filter)
            ->where('invoice_items.line_type', LineType::JEWELLERY_ITEM->value)
            ->selectRaw("COALESCE(NULLIF(invoice_items.category_snapshot, ''), 'Uncategorised') as category")
            ->selectRaw('COALESCE(SUM(invoice_items.quantity), 0) as pieces')
            ->selectRaw('COALESCE(SUM(invoice_items.line_total), 0) as revenue')
            ->groupByRaw("COALESCE(NULLIF(invoice_items.category_snapshot, ''), 'Uncategorised')")
            ->orderByRaw('COALESCE(SUM(invoice_items.line_total), 0) DESC')
            ->get()
            ->map(fn ($row): array => [
                'category' => (string) $row->category,
                'pieces' => $this->toInt($row->pieces),
                'revenue' => $this->toFloat($row->revenue),
            ]);
    }

    /**
     * Invoice count and revenue per selling staff member.
     *
     * created_by_role is read from the invoice, not from the user record, so a
     * cashier later promoted to manager still appears as the cashier they were
     * at the time of the sale.
     *
     * @return Collection<int, array{
     *     user_id: ?int, staff_name: ?string, staff_role: ?string,
     *     user_status: ?string, user_deleted_at: ?string,
     *     invoice_count: int, net_revenue: float, average_sale: float
     * }>
     */
    public function byStaff(ReportFilter $filter): Collection
    {
        return $this->completedInvoices($filter)
            ->leftJoin('users', 'users.id', '=', 'invoices.created_by')
            ->selectRaw('invoices.created_by as user_id')
            ->selectRaw('users.name as staff_name')
            ->selectRaw('users.status as user_status')
            ->selectRaw('users.deleted_at as user_deleted_at')
            ->selectRaw('invoices.created_by_role as staff_role')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(invoices.grand_total), 0) as net_revenue')
            ->groupBy('invoices.created_by', 'users.name', 'users.status', 'users.deleted_at', 'invoices.created_by_role')
            ->orderByRaw('COALESCE(SUM(invoices.grand_total), 0) DESC')
            ->get()
            ->map(function ($row): array {
                $count = $this->toInt($row->invoice_count);
                $revenue = $this->toFloat($row->net_revenue);

                return [
                    'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                    'staff_name' => $row->staff_name,
                    'staff_role' => $row->staff_role,
                    'user_status' => $row->user_status,
                    'user_deleted_at' => $row->user_deleted_at,
                    'invoice_count' => $count,
                    'net_revenue' => $revenue,
                    'average_sale' => $count > 0 ? $revenue / $count : 0.0,
                ];
            });
    }

    /**
     * Net grams of metal sold, broken down by the purity recorded on the sale.
     *
     * A core jewellery metric revenue alone hides: a heavy 18K month and a
     * light 22K month can produce identical revenue. Net weight is used (gross
     * minus stone weight) because that is the metal actually sold.
     *
     * @return Collection<int, array{purity: string, pieces: int, net_weight: float, gross_weight: float, revenue: float}>
     */
    public function goldWeightSoldByPurity(ReportFilter $filter): Collection
    {
        return $this->lineQuery($filter)
            ->where('invoice_items.line_type', LineType::JEWELLERY_ITEM->value)
            ->selectRaw("COALESCE(NULLIF(invoice_items.purity_snapshot, ''), 'Unspecified') as purity")
            ->selectRaw('COALESCE(SUM(invoice_items.quantity), 0) as pieces')
            ->selectRaw('COALESCE(SUM(invoice_items.net_weight_snapshot * invoice_items.quantity), 0) as net_weight')
            ->selectRaw('COALESCE(SUM(invoice_items.gross_weight_snapshot * invoice_items.quantity), 0) as gross_weight')
            ->selectRaw('COALESCE(SUM(invoice_items.line_total), 0) as revenue')
            ->groupByRaw("COALESCE(NULLIF(invoice_items.purity_snapshot, ''), 'Unspecified')")
            ->orderByRaw('COALESCE(SUM(invoice_items.net_weight_snapshot * invoice_items.quantity), 0) DESC')
            ->get()
            ->map(fn ($row): array => [
                'purity' => (string) $row->purity,
                'pieces' => $this->toInt($row->pieces),
                'net_weight' => $this->toFloat($row->net_weight),
                'gross_weight' => $this->toFloat($row->gross_weight),
                'revenue' => $this->toFloat($row->revenue),
            ]);
    }

    /**
     * Total net grams of metal sold in the period.
     */
    public function goldWeightSold(ReportFilter $filter): float
    {
        return $this->toFloat(
            $this->lineQuery($filter)
                ->where('invoice_items.line_type', LineType::JEWELLERY_ITEM->value)
                ->sum(DB::raw('invoice_items.net_weight_snapshot * invoice_items.quantity'))
        );
    }

    /**
     * Number of jewellery pieces sold in the period.
     */
    public function piecesSold(ReportFilter $filter): int
    {
        return $this->toInt(
            $this->lineQuery($filter)
                ->where('invoice_items.line_type', LineType::JEWELLERY_ITEM->value)
                ->sum('invoice_items.quantity')
        );
    }

    /**
     * Discount given per invoice, with the discount as a share of that
     * invoice's gross so unusually generous discounting stands out.
     *
     * @return Collection<int, array{
     *     uuid: string, invoice_number: string, invoice_date: string,
     *     customer: string, gross: float, discount: float, discount_percent: float,
     *     net: float, applied_by: ?string, applied_by_role: ?string
     * }>
     */
    public function discountedInvoices(ReportFilter $filter): Collection
    {
        return $this->completedInvoices($filter)
            ->where('invoices.discount_amount', '>', 0)
            ->leftJoin('users', 'users.id', '=', 'invoices.created_by')
            ->selectRaw('invoices.uuid, invoices.invoice_number, invoices.invoice_date')
            ->selectRaw('invoices.customer_name_snapshot as customer')
            ->selectRaw('invoices.subtotal as gross, invoices.discount_amount as discount, invoices.grand_total as net')
            ->selectRaw('users.name as applied_by, invoices.created_by_role as applied_by_role')
            ->orderByDesc('invoices.discount_amount')
            ->get()
            ->map(function ($row): array {
                $gross = $this->toFloat($row->gross);
                $discount = $this->toFloat($row->discount);

                return [
                    'uuid' => (string) $row->uuid,
                    'invoice_number' => (string) $row->invoice_number,
                    'invoice_date' => (string) $row->invoice_date,
                    'customer' => (string) $row->customer,
                    'gross' => $gross,
                    'discount' => $discount,
                    'discount_percent' => $gross > 0 ? $discount / $gross * 100 : 0.0,
                    'net' => $this->toFloat($row->net),
                    'applied_by' => $row->applied_by,
                    'applied_by_role' => $row->applied_by_role,
                ];
            });
    }

    /**
     * Discount totals grouped by the staff member who applied them.
     *
     * @return Collection<int, array{staff_name: ?string, staff_role: ?string, invoice_count: int, discount_total: float, gross_total: float, discount_percent: float}>
     */
    public function discountByStaff(ReportFilter $filter): Collection
    {
        return $this->completedInvoices($filter)
            ->where('invoices.discount_amount', '>', 0)
            ->leftJoin('users', 'users.id', '=', 'invoices.created_by')
            ->selectRaw('users.name as staff_name, invoices.created_by_role as staff_role')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(invoices.discount_amount), 0) as discount_total')
            ->selectRaw('COALESCE(SUM(invoices.subtotal), 0) as gross_total')
            ->groupBy('users.name', 'invoices.created_by_role')
            ->orderByRaw('COALESCE(SUM(invoices.discount_amount), 0) DESC')
            ->get()
            ->map(function ($row): array {
                $gross = $this->toFloat($row->gross_total);
                $discount = $this->toFloat($row->discount_total);

                return [
                    'staff_name' => $row->staff_name,
                    'staff_role' => $row->staff_role,
                    'invoice_count' => $this->toInt($row->invoice_count),
                    'discount_total' => $discount,
                    'gross_total' => $gross,
                    'discount_percent' => $gross > 0 ? $discount / $gross * 100 : 0.0,
                ];
            });
    }

    /**
     * Tax collected per period bucket, for filing.
     *
     * @return Collection<int, array{bucket: string, invoice_count: int, taxable_base: float, tax_total: float}>
     */
    public function taxCollected(ReportFilter $filter): Collection
    {
        $expression = $filter->grouping->sqlExpression('invoices.invoice_date');

        return $this->completedInvoices($filter)
            ->selectRaw("{$expression} as bucket")
            ->selectRaw('COUNT(*) as invoice_count')
            // Tax applies after discount, so the taxable base is gross less discount.
            ->selectRaw('COALESCE(SUM(subtotal - discount_amount), 0) as taxable_base')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as tax_total')
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->get()
            ->map(fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'invoice_count' => $this->toInt($row->invoice_count),
                'taxable_base' => $this->toFloat($row->taxable_base),
                'tax_total' => $this->toFloat($row->tax_total),
            ]);
    }

    /**
     * Voided invoices for the period — reported separately, never netted off
     * revenue, with the reason and the actor's role at the time of voiding.
     *
     * @return Collection<int, array{
     *     uuid: string, invoice_number: string, invoice_date: string,
     *     customer: string, original_value: float, void_reason: ?string,
     *     voided_by: ?string, voided_by_role: ?string, voided_at: ?string
     * }>
     */
    public function voidedInvoices(ReportFilter $filter): Collection
    {
        $query = $this->unscoped(Invoice::class)->voided();

        $this->scopeToBranch($query, $filter->branchId, 'invoices');
        $this->scopeToDateRange($query, $filter, 'invoices.invoice_date');

        return $query
            ->leftJoin('users', 'users.id', '=', 'invoices.voided_by')
            ->selectRaw('invoices.uuid, invoices.invoice_number, invoices.invoice_date')
            ->selectRaw('invoices.customer_name_snapshot as customer')
            ->selectRaw('invoices.grand_total as original_value')
            ->selectRaw('invoices.void_reason, invoices.voided_by_role, invoices.voided_at')
            ->selectRaw('users.name as voided_by')
            ->orderByDesc('invoices.voided_at')
            ->get()
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'invoice_number' => (string) $row->invoice_number,
                'invoice_date' => (string) $row->invoice_date,
                'customer' => (string) $row->customer,
                'original_value' => $this->toFloat($row->original_value),
                'void_reason' => $row->void_reason,
                'voided_by' => $row->voided_by,
                'voided_by_role' => $row->voided_by_role,
                'voided_at' => $row->voided_at,
            ]);
    }

    /**
     * Count and total original value of voided invoices in the period.
     *
     * @return array{count: int, value: float}
     */
    public function voidedTotals(ReportFilter $filter): array
    {
        $query = $this->unscoped(Invoice::class)->voided();

        $this->scopeToBranch($query, $filter->branchId, 'invoices');
        $this->scopeToDateRange($query, $filter, 'invoices.invoice_date');

        $row = $query
            ->selectRaw('COUNT(*) as void_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as void_value')
            ->first();

        return [
            'count' => $this->toInt($row?->void_count),
            'value' => $this->toFloat($row?->void_value),
        ];
    }

    /**
     * Loose stone sales — lines sold as stones rather than as finished pieces.
     *
     * Carat comes from the invoice_item_stones snapshot rows attached to the
     * line, summed in SQL through a correlated sub-select so no rows are pulled
     * into PHP.
     *
     * @return Collection<int, array{stone_name: string, stone_type: string, quantity: int, carat: float, revenue: float}>
     */
    public function looseStoneSales(ReportFilter $filter): Collection
    {
        return $this->lineQuery($filter)
            ->where('invoice_items.line_type', LineType::LOOSE_STONE->value)
            ->leftJoin('invoice_item_stones', 'invoice_item_stones.invoice_item_id', '=', 'invoice_items.id')
            ->selectRaw('invoice_items.name_snapshot as stone_name')
            ->selectRaw("COALESCE(NULLIF(invoice_item_stones.stone_type_snapshot, ''), 'Unspecified') as stone_type")
            ->selectRaw('COALESCE(SUM(invoice_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(invoice_item_stones.carat_snapshot * invoice_items.quantity), 0) as carat')
            ->selectRaw('COALESCE(SUM(invoice_items.line_total), 0) as revenue')
            ->groupByRaw("invoice_items.name_snapshot, COALESCE(NULLIF(invoice_item_stones.stone_type_snapshot, ''), 'Unspecified')")
            ->orderByRaw('COALESCE(SUM(invoice_items.line_total), 0) DESC')
            ->get()
            ->map(fn ($row): array => [
                'stone_name' => (string) $row->stone_name,
                'stone_type' => (string) $row->stone_type,
                'quantity' => $this->toInt($row->quantity),
                'carat' => $this->toFloat($row->carat),
                'revenue' => $this->toFloat($row->revenue),
            ]);
    }

    /**
     * Most recent completed invoices for a dashboard list.
     *
     * @return Collection<int, array{uuid: string, invoice_number: string, invoice_date: string, customer: string, grand_total: float, payment_method: string}>
     */
    public function recentInvoices(?int $branchId, int $limit, ?int $createdBy = null): Collection
    {
        $query = $this->unscoped(Invoice::class)->completed();

        $this->scopeToBranch($query, $branchId, 'invoices');

        // A Cashier dashboard shows only their own sales, per spec 8.4.
        if ($createdBy !== null) {
            $query->where('invoices.created_by', $createdBy);
        }

        return $query
            ->orderByDesc('invoices.invoice_date')
            ->orderByDesc('invoices.id')
            ->limit($limit)
            ->get(['uuid', 'invoice_number', 'invoice_date', 'customer_name_snapshot', 'grand_total', 'payment_method'])
            ->map(fn (Invoice $invoice): array => [
                'uuid' => (string) $invoice->uuid,
                'invoice_number' => (string) $invoice->invoice_number,
                'invoice_date' => (string) $invoice->invoice_date?->toDateString(),
                'customer' => (string) $invoice->customer_name_snapshot,
                'grand_total' => (float) $invoice->grand_total,
                'payment_method' => $invoice->payment_method?->label() ?? '',
            ]);
    }

    /**
     * Completed invoice count and revenue for one staff member in a period.
     *
     * Powers the "My Invoices Today" card on the Cashier dashboard.
     *
     * @return array{count: int, revenue: float}
     */
    public function totalsForStaff(ReportFilter $filter, int $userId): array
    {
        $row = $this->completedInvoices($filter)
            ->where('invoices.created_by', $userId)
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as net_revenue')
            ->first();

        return [
            'count' => $this->toInt($row?->invoice_count),
            'revenue' => $this->toFloat($row?->net_revenue),
        ];
    }

    /**
     * Completed invoices joined to their line items.
     *
     * Deliberately joins only invoice_items (a snapshot table), never
     * jewellery_items or stones, per spec 3.2.
     */
    private function lineQuery(ReportFilter $filter): Builder
    {
        $query = $this->completedInvoices($filter)
            ->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id');

        // Category and purity filters compare against the snapshot *name*
        // carried on the line, not a foreign key — the sale is reported under
        // the category and purity it was sold as, even if the live record has
        // since been renamed or reassigned.
        if ($filter->hasOption('category_id_name')) {
            $query->where('invoice_items.category_snapshot', $filter->option('category_id_name'));
        }

        if ($filter->hasOption('purity_id_name')) {
            $query->where('invoice_items.purity_snapshot', $filter->option('purity_id_name'));
        }

        return $query;
    }

    /**
     * Apply the optional secondary filters an operator may have selected.
     *
     * Values are already resolved to ids or validated enum strings by
     * ReportFilterRequest, so nothing here is raw request input.
     */
    private function applyOptionalFilters(Builder $query, ReportFilter $filter): void
    {
        if ($filter->hasOption('payment_method')) {
            $query->where('invoices.payment_method', $filter->option('payment_method'));
        }

        if ($filter->hasOption('staff_id')) {
            $query->where('invoices.created_by', $filter->option('staff_id'));
        }

        if ($filter->hasOption('customer_id')) {
            $query->where('invoices.customer_id', $filter->option('customer_id'));
        }
    }
}
