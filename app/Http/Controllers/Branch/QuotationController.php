<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\ConvertQuotationRequest;
use App\Http\Requests\Branch\StoreDirectInvoiceRequest;
use App\Http\Requests\Branch\StoreQuotationRequest;
use App\Models\Customer;
use App\Models\Quotation;
use App\Repositories\GoldRateRepository;
use App\Services\InvoiceConversionService;
use App\Services\PricingCalculationService;
use App\Services\QuotationDraftService;
use App\Services\QuotationService;
use App\Support\Settings\DocumentSettings;
use App\Services\SalesLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * QuotationController
 *
 * HTTP layer for quotation list, POS-backed create, draft edit, lookup,
 * conversion, and direct invoice issue from POS.
 */
class QuotationController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private QuotationService $quotationService,
        private QuotationDraftService $draftService,
        private SalesLookupService $lookupService,
        private PricingCalculationService $pricingService,
        private GoldRateRepository $goldRateRepository,
        private InvoiceConversionService $conversionService,
    ) {}

    /**
     * Quotation list.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Quotation::class);

        return view('branch.quotations.index', [
            'quotations' => $this->quotationService->paginate($request->only(['status', 'from', 'to', 'search'])),
            'filters' => $request->only(['status', 'from', 'to', 'search']),
        ]);
    }

    /**
     * New documents are built on POS — this URL stays so old bookmarks work.
     */
    public function create(): RedirectResponse
    {
        $this->authorize('create', Quotation::class);

        return redirect()->route('branch.pos.show');
    }

    /**
     * Edit draft quotation workspace.
     */
    public function edit(Quotation $quotation): View
    {
        $this->authorize('update', $quotation);

        return view('branch.quotations.form', $this->formContext($quotation));
    }

    /**
     * Persist new quotation.
     */
    public function store(StoreQuotationRequest $request): RedirectResponse|JsonResponse
    {
        return $this->persist($request, null);
    }

    /**
     * Update existing draft quotation.
     */
    public function update(StoreQuotationRequest $request, Quotation $quotation): RedirectResponse|JsonResponse
    {
        return $this->persist($request, $quotation);
    }

    /**
     * Shared create/update path for the quotation workspace.
     *
     * Business rule breaches surface as field errors, while anything unexpected
     * (driver faults, constraint violations) is logged and reported as a generic
     * message — the operator must never see SQL or a stack trace.
     */
    private function persist(StoreQuotationRequest $request, ?Quotation $quotation): RedirectResponse|JsonResponse
    {
        try {
            $quotation = $this->quotationService->save($request->validated(), $request->user(), $quotation);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
            }

            throw $e;
        } catch (Throwable $e) {
            Log::error('Quotation save failed.', [
                'quotation_id' => $quotation?->id,
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            $message = 'The quotation could not be saved. Please try again or contact support.';

            if ($request->expectsJson()) {
                return $this->jsonFailure($message, [], 500);
            }

            return back()->withInput()->withErrors(['quotation' => $message]);
        }

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Quotation saved.', [
                'uuid' => $quotation->uuid,
                'print_url' => route('branch.quotations.print', $quotation),
            ]);
        }

        return redirect()->route('branch.quotations.show', $quotation)->with('success', 'Quotation saved.');
    }

    /**
     * Quotation detail.
     */
    public function show(Quotation $quotation): View
    {
        $this->authorize('view', $quotation);

        return view('branch.quotations.show', [
            'quotation' => $this->quotationService->loadDetail($quotation),
        ]);
    }

    /**
     * Print view.
     */
    public function print(Quotation $quotation): View
    {
        $this->authorize('view', $quotation);

        return view('branch.quotations.print', [
            'quotation' => $this->quotationService->loadDetail($quotation),
        ]);
    }

    /**
     * Cancel draft quotation.
     */
    public function cancel(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorize('cancel', $quotation);
        $this->quotationService->cancel($quotation, $request->user());

        return redirect()->route('branch.quotations.index')->with('success', 'Quotation cancelled.');
    }

    /**
     * Convert to invoice.
     */
    public function convert(ConvertQuotationRequest $request, Quotation $quotation): RedirectResponse
    {
        $invoice = $this->conversionService->convert($quotation, $request->validated(), $request->user());

        return redirect()->route('branch.invoices.show', $invoice)->with('success', 'Invoice created successfully.');
    }

    /**
     * Issue a completed invoice from the workspace without saving a quotation.
     *
     * JSON clients receive the invoice uuid for redirect; a non-AJAX POST
     * still lands on the invoice show page.
     */
    public function storeInvoice(StoreDirectInvoiceRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $validated = $request->validated();
            $invoice = $this->conversionService->createFromWorkspace(
                $validated,
                [
                    'payment_method' => $validated['payment_method'],
                    'payment_reference' => $validated['payment_reference'] ?? null,
                ],
                $request->user()
            );
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
            }

            throw $e;
        } catch (Throwable $e) {
            Log::error('Direct invoice from workspace failed.', [
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            $message = 'The invoice could not be created. Please try again or contact support.';

            if ($request->expectsJson()) {
                return $this->jsonFailure($message, [], 500);
            }

            return back()->withInput()->withErrors(['invoice' => $message]);
        }

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Invoice created successfully.', [
                'uuid' => $invoice->uuid,
                'print_url' => route('branch.invoices.print', $invoice),
            ]);
        }

        return redirect()->route('branch.invoices.show', $invoice)->with('success', 'Invoice created successfully.');
    }

    /**
     * Barcode, short-code, or unique-name lookup for the quotation workspace.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('create', Quotation::class);

        $request->validate(['code' => ['required', 'string', 'max:80']]);

        $result = $this->lookupService->lookup($request->input('code'), $request->user());

        if ($result['status'] === 'found') {
            return $this->jsonSuccess('Found.', ['line' => $result['line']]);
        }

        return $this->jsonFailure($result['message'] ?? 'Not found.', ['code' => [$result['message'] ?? 'Not found.']], 422);
    }

    /**
     * Typeahead, or the full addable catalog when catalog=1 (POS caches it).
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('create', Quotation::class);

        $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'catalog' => ['sometimes', 'boolean'],
        ]);

        $items = $request->boolean('catalog')
            ? $this->lookupService->catalog($request->user())
            : $this->lookupService->search((string) $request->query('q', ''), $request->user());

        return $this->jsonSuccess('OK.', [
            'items' => $items,
        ]);
    }

    /**
     * Server-side draft autosave.
     */
    public function saveDraft(Request $request): JsonResponse
    {
        $this->authorize('create', Quotation::class);

        $payload = $request->validate([
            'payload' => ['required', 'array'],
        ])['payload'];

        $this->draftService->save($request->user(), $payload, $payload['quotation_id'] ?? null);

        return $this->jsonSuccess('Draft saved.');
    }

    /**
     * Discard server draft.
     */
    public function discardDraft(Request $request): JsonResponse
    {
        $this->draftService->discard($request->user());

        return $this->jsonSuccess('Draft discarded.');
    }

    /**
     * Live recalc preview (optional AJAX).
     */
    public function recalculate(Request $request): JsonResponse
    {
        $this->authorize('create', Quotation::class);

        $data = $request->validate([
            'lines' => ['required', 'array'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'tax_type' => ['nullable', 'string'],
            'tax_rate' => ['nullable', 'numeric'],
            'apply_tax' => ['sometimes', 'boolean'],
        ]);

        $data = DocumentSettings::applyToPayload($data, $request->user());
        $result = $this->pricingService->recalculate($data['lines'], $data);

        return $this->jsonSuccess('OK', $result);
    }

    /**
     * Shared form view data.
     *
     * @return array<string, mixed>
     */
    private function formContext(?Quotation $quotation): array
    {
        $user = request()->user();
        $draft = $this->draftService->current($user);
        $payload = $quotation ? $this->quotationService->toPayload($quotation) : ($draft ?? []);
        $tax = DocumentSettings::tax();

        // Live SuperAdmin tax wins over a stale draft or an older saved draft.
        $payload['tax_type'] = $tax['type'];
        $payload['tax_rate'] = $tax['rate'];

        return [
            'quotation' => $quotation,
            'payload' => $payload,
            'rateMap' => $this->goldRateRepository->currentRateMap(),
            'customers' => Customer::query()->active()->orderBy('name')->limit(100)->get(),
            'scanner' => scanner_config(),
            'draftDebounceMs' => (int) config('annigems.quotations.draft_debounce_ms', 800),
            'canDiscount' => $user->hasPermission('invoices.discount') && (bool) setting('discount_enabled'),
            'maxDiscountPercentage' => DocumentSettings::maxDiscountPercentage($user),
            'taxEnabled' => $tax['enabled'],
            'taxType' => $tax['type'],
            'taxRate' => $tax['rate'],
            'taxLabel' => $tax['label'],
            'canConvert' => $quotation ? $user->can('convert', $quotation) : $user->hasPermission('quotations.convert'),
            'paymentMethods' => \App\Enums\PaymentMethod::options(),
        ];
    }
}
