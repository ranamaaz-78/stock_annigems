<?php

namespace App\Services\Reports\SuperAdmin;

use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportResult;
use InvalidArgumentException;

/**
 * SuperAdminReportService
 *
 * Dispatcher for the SuperAdmin reporting surface.
 *
 * It is the mirror of AdminReportService and shares none of its wiring: the
 * services it depends on all extend AggregateReportService and none of them can
 * return a record row. That structural separation, rather than a runtime flag,
 * is what enforces spec 7.4 — there is no branch of code here that could be
 * made to return an invoice, quotation, item, stone, or purchase.
 *
 * The slug is validated against ReportCatalog::findSuperAdminReport() before it
 * arrives, so an Admin-only report key 404s at the controller and never reaches
 * this match.
 */
class SuperAdminReportService
{
    public function __construct(
        private AggregateSalesReportService $sales,
        private AggregateQuotationReportService $quotations,
        private AggregateInventoryReportService $inventory,
        private AggregateStoneReportService $stones,
        private AggregateCustomerReportService $customers,
        private AggregateAuditReportService $audit,
        private BranchComparisonReportService $comparison,
    ) {}

    /**
     * Build one aggregate report.
     *
     * @param  string  $key A slug already validated against the SuperAdmin catalog
     *
     * @throws InvalidArgumentException when the slug has no aggregate builder
     */
    public function build(string $key, ReportFilter $filter): ReportResult
    {
        return match ($key) {
            'sales-summary' => $this->sales->summary($filter),
            'sales-by-payment-method' => $this->sales->byPaymentMethod($filter),
            'sales-by-category' => $this->sales->byCategory($filter),
            'sales-by-staff' => $this->sales->byStaff($filter),
            'gold-weight-sold' => $this->sales->goldWeightSold($filter),
            'discount-report' => $this->sales->discounts($filter),
            'tax-collected' => $this->sales->taxCollected($filter),
            'voided-invoices' => $this->sales->voided($filter),

            'quotation-funnel' => $this->quotations->funnel($filter),
            'cancelled-quotations' => $this->quotations->cancelled($filter),

            'stock-summary' => $this->inventory->stockSummary($filter),
            'gold-weight-in-stock' => $this->inventory->goldWeightInStock($filter),
            'category-stock' => $this->inventory->categoryStock($filter),
            'aging-stock' => $this->inventory->agingStock($filter),

            'stone-stock-summary' => $this->stones->stockSummary($filter),
            'stone-low-stock' => $this->stones->lowStock($filter),
            'stone-intake-history' => $this->stones->intakeHistory($filter),
            'stone-consumption' => $this->stones->consumption($filter),

            'top-customers' => $this->customers->topCustomers($filter),
            'cross-branch-customers' => $this->customers->crossBranch($filter),
            'new-customers' => $this->customers->newCustomers($filter),
            'repeat-customers' => $this->customers->repeatCustomers($filter),

            'user-activity' => $this->audit->userActivity($filter),
            'gold-rate-history' => $this->audit->goldRateHistory($filter),

            'branch-comparison' => $this->comparison->branchComparison($filter),
            'stock-distribution' => $this->comparison->stockDistribution($filter),

            default => throw new InvalidArgumentException("No aggregate builder is registered for report [{$key}]."),
        };
    }
}
