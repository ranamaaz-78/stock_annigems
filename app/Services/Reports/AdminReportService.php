<?php

namespace App\Services\Reports;

use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportResult;
use InvalidArgumentException;

/**
 * AdminReportService
 *
 * Single entry point for the branch (Admin) reporting surface: it maps a report
 * slug to the service that builds it.
 *
 * The controller therefore holds no report knowledge at all — it validates,
 * authorises, dispatches here, and renders. Adding a report means adding a
 * catalog entry and one arm to the match below; no route and no controller
 * change is needed.
 *
 * The match is exhaustive by design. An unknown slug throws rather than falling
 * through to a default, so a typo in the catalog surfaces immediately instead of
 * rendering a silently empty page.
 */
class AdminReportService
{
    public function __construct(
        private SalesReportService $sales,
        private QuotationReportService $quotations,
        private InventoryReportService $inventory,
        private StoneReportService $stones,
        private CustomerReportService $customers,
        private AuditReportService $audit,
        private CreditReportService $credit,
    ) {}

    /**
     * Build one report.
     *
     * @param  string  $key A slug already validated against ReportCatalog
     *
     * @throws InvalidArgumentException when the slug has no builder
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
            'loose-stone-sales' => $this->sales->looseStoneSales($filter),

            'quotation-funnel' => $this->quotations->funnel($filter),
            'pending-quotations' => $this->quotations->pending($filter),
            'time-to-convert' => $this->quotations->timeToConvert($filter),
            'cancelled-quotations' => $this->quotations->cancelled($filter),

            'stock-summary' => $this->inventory->stockSummary($filter),
            'gold-weight-in-stock' => $this->inventory->goldWeightInStock($filter),
            'category-stock' => $this->inventory->categoryStock($filter),
            'aging-stock' => $this->inventory->agingStock($filter),
            'no-movement-items' => $this->inventory->noMovementItems($filter),
            'item-movement-log' => $this->inventory->movementLog($filter),

            'stone-stock-summary' => $this->stones->stockSummary($filter),
            'stone-valuation' => $this->stones->valuation($filter),
            'stone-low-stock' => $this->stones->lowStock($filter),
            'stone-intake-history' => $this->stones->intakeHistory($filter),
            'stone-consumption' => $this->stones->consumption($filter),
            'stone-movement-ledger' => $this->stones->movementLedger($filter),
            'stone-movement-log' => $this->stones->movementLedger($filter),

            'performa-outstanding' => $this->credit->performaOutstanding($filter),
            'delivery-notes' => $this->credit->deliveryNotes($filter),

            'top-customers' => $this->customers->topCustomers($filter),
            'cross-branch-customers' => $this->customers->crossBranch($filter),
            'new-customers' => $this->customers->newCustomers($filter),
            'repeat-customers' => $this->customers->repeatCustomers($filter),
            'customer-purchase-history' => $this->customers->purchaseHistory($filter),

            'user-activity' => $this->audit->userActivity($filter),
            'gold-rate-history' => $this->audit->goldRateHistory($filter),
            'document-audit' => $this->audit->documentTrail($filter),

            default => throw new InvalidArgumentException("No builder is registered for report [{$key}]."),
        };
    }
}
