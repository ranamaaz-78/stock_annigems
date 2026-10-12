<?php

namespace App\Http\Controllers\Branch;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\DeliveryNote;
use App\Models\Quotation;
use App\Repositories\GoldRateRepository;
use App\Services\CodeSequenceService;
use App\Support\Settings\DocumentSettings;
use Illuminate\Http\JsonResponse;
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
        private CodeSequenceService $codeSequence,
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
            'canDeliver' => $user->can('create', DeliveryNote::class),
            'paymentMethods' => PaymentMethod::options(),
            'documentNumbers' => $this->documentNumbers(),
        ]);
    }

    /**
     * The numbers the next quotation and invoice will get, for the ticket's
     * "Document no." field. Called again after every save so the screen moves
     * on to the following number.
     */
    public function numbers(): JsonResponse
    {
        $this->authorize('create', Quotation::class);

        return response()->json(['success' => true, 'data' => $this->documentNumbers()]);
    }

    /**
     * @return array{quotation: string, invoice: string, delivery_note: string}
     */
    private function documentNumbers(): array
    {
        return [
            'quotation' => $this->codeSequence->peekQuotationNumber(),
            'invoice' => $this->codeSequence->peekInvoiceNumber(),
            'delivery_note' => $this->codeSequence->peekDeliveryNoteNumber(),
        ];
    }
}
