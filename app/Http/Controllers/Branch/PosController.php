<?php

namespace App\Http\Controllers\Branch;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Repositories\GoldRateRepository;
use App\Support\Settings\DocumentSettings;
use Illuminate\View\View;

/**
 * PosController
 *
 * Fullscreen cashier workspace: phone-first customer, live ticket, and
 * generate-then-print. Business logic stays in QuotationService /
 * InvoiceConversionService — this controller only authorizes and renders.
 */
class PosController extends Controller
{
    public function __construct(
        private GoldRateRepository $goldRateRepository,
    ) {}

    /**
     * Chrome-free POS terminal. Requires quotations.create; invoice generate
     * is hidden unless the operator also has quotations.convert.
     */
    public function show(): View
    {
        $this->authorize('create', Quotation::class);

        $user = request()->user();
        $tax = DocumentSettings::tax();

        return view('branch.pos.index', [
            'branch' => $user->branch,
            'rateMap' => $this->goldRateRepository->currentRateMap(),
            'scanner' => scanner_config(),
            'canDiscount' => $user->hasPermission('invoices.discount') && (bool) setting('discount_enabled'),
            'maxDiscountPercentage' => DocumentSettings::maxDiscountPercentage($user),
            'taxEnabled' => $tax['enabled'],
            'taxType' => $tax['type'],
            'taxRate' => $tax['rate'],
            'taxLabel' => $tax['label'],
            'canConvert' => $user->hasPermission('quotations.convert'),
            'paymentMethods' => PaymentMethod::options(),
        ]);
    }
}
