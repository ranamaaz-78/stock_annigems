<?php

namespace App\Http\Controllers\Branch;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Creditor;
use App\Models\DeliveryNote;
use App\Models\Performa;
use App\Models\Quotation;
use App\Models\TaxRate;
use App\Services\PerformaService;
use App\Services\TaxRateService;
use App\Repositories\GoldRateRepository;
use App\Services\CodeSequenceService;
use App\Support\Settings\DocumentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        private TaxRateService $taxRates,
        private PerformaService $performaService,
    ) {}

    /**
     * Chrome-free POS terminal. Requires quotations.create; invoice generate
     * is hidden unless the operator also has quotations.convert.
     */
    public function show(Request $request): View
    {
        $this->authorize('create', Quotation::class);

        $user = $request->user();
        $tax = DocumentSettings::tax();

        $canConvert = $user->hasPermission('quotations.convert');
        $canDeliver = $user->can('create', DeliveryNote::class);
        $canPerforma = $user->can('create', Performa::class);

        // ?kind= opens the POS on that document (the old "New Performa" address
        // lands here); one the user may not issue falls back to the usual default.
        $kind = (string) $request->query('kind', '');
        $allowed = [
            'invoice' => $canConvert,
            'quotation' => true,
            'delivery_note' => $canDeliver,
            'performa' => $canPerforma,
        ];
        $defaultKind = ($allowed[$kind] ?? false) ? $kind : ($canConvert ? 'invoice' : 'quotation');

        // ?performa=uuid opens an issued performa for editing: its lines load
        // into the ticket and saving updates it instead of issuing a new one.
        $editingPerforma = null;
        if (filled($request->query('performa'))) {
            $performa = Performa::query()->where('uuid', (string) $request->query('performa'))->firstOrFail();
            $this->authorize('update', $performa);
            $editingPerforma = $this->performaService->editPayload($performa);
            $defaultKind = 'performa';
        }

        // ?creditor=uuid fills the creditor on a performa (from a creditor's page).
        $initialCreditor = null;
        if ($defaultKind === 'performa' && filled($request->query('creditor'))) {
            $initialCreditor = Creditor::query()
                ->active()
                ->where('uuid', (string) $request->query('creditor'))
                ->first(['uuid', 'creditor_code', 'name', 'phone', 'email']);
        }

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
            // The rates SuperAdmin keeps; the cashier picks from them.
            'ivaRates' => $this->taxRates->active(TaxRate::KIND_IVA)->map(fn (TaxRate $r): array => $this->rateRow($r))->values()->all(),
            'recargoRates' => $this->taxRates->active(TaxRate::KIND_RECARGO)->map(fn (TaxRate $r): array => $this->rateRow($r))->values()->all(),
            'defaultIvaUuid' => $tax['uuid'],
            'canConvert' => $canConvert,
            'canDeliver' => $canDeliver,
            'canPerforma' => $canPerforma,
            'defaultKind' => $defaultKind,
            'editingPerforma' => $editingPerforma,
            'initialCreditor' => $initialCreditor ? [
                'uuid' => $initialCreditor->uuid,
                'customer_code' => $initialCreditor->creditor_code,
                'name' => $initialCreditor->name,
                'phone' => $initialCreditor->phone,
                'email' => $initialCreditor->email,
            ] : null,
            'paymentMethods' => PaymentMethod::options(),
            'documentNumbers' => $this->documentNumbers(),
        ]);
    }

    /**
     * One rate as the POS needs it: its uuid, name and percentage.
     *
     * @return array{uuid: string, name: string, rate: float, exempt: bool}
     */
    private function rateRow(TaxRate $rate): array
    {
        return [
            'uuid' => $rate->uuid,
            'name' => $rate->name,
            'rate' => (float) $rate->rate,
            'exempt' => (bool) $rate->is_exempt,
        ];
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
     * @return array{quotation: string, invoice: string, delivery_note: string, performa: string}
     */
    private function documentNumbers(): array
    {
        return [
            'quotation' => $this->codeSequence->peekQuotationNumber(),
            'invoice' => $this->codeSequence->peekInvoiceNumber(),
            'delivery_note' => $this->codeSequence->peekDeliveryNoteNumber(),
            'performa' => $this->codeSequence->peekPerformaNumber(),
        ];
    }
}
