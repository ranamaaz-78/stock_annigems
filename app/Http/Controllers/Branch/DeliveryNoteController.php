<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\CancelDeliveryNoteRequest;
use App\Http\Requests\Branch\ConvertDeliveryNoteRequest;
use App\Http\Requests\Branch\StoreDeliveryNoteRequest;
use App\Http\Requests\Branch\UpdateDeliveryNoteRequest;
use App\Models\DeliveryNote;
use App\Models\TaxRate;
use App\Services\DeliveryNoteService;
use App\Services\TaxRateService;
use App\Support\Settings\DocumentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;
use Illuminate\Support\Facades\Log;

/**
 * DeliveryNoteController
 *
 * HTTP layer for delivery notes (albarán): list, detail, print, issue from
 * the POS, cancel, and convert to an invoice. Rules live in DeliveryNoteService.
 */
class DeliveryNoteController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private DeliveryNoteService $deliveryNoteService,
        private TaxRateService $taxRates,
    ) {}

    /**
     * Delivery note list. New notes are made from the POS.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DeliveryNote::class);

        $filters = $request->only(['status', 'from', 'to', 'search']);

        return view('branch.delivery-notes.index', [
            'notes' => $this->deliveryNoteService->paginate($filters),
            'filters' => $filters,
        ]);
    }

    /**
     * Issue a note from the POS and take the stock out.
     */
    public function store(StoreDeliveryNoteRequest $request): JsonResponse|RedirectResponse
    {
        try {
            $note = $this->deliveryNoteService->create($request->validated(), $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
            }

            throw $e;
        } catch (Throwable $e) {
            Log::error('Delivery note issue failed.', [
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            $message = 'The delivery note could not be saved. Please try again or contact support.';

            if ($request->expectsJson()) {
                return $this->jsonFailure($message, [], 500);
            }

            return back()->withInput()->withErrors(['delivery_note' => $message]);
        }

        if ($request->expectsJson()) {
            // The POS opens the document's page next; this is what that page shows.
            session()->flash('success', 'Delivery note '.$note->delivery_note_number.' created.');

            return $this->jsonSuccess('Delivery note '.$note->delivery_note_number.' created.', [
                'uuid' => $note->uuid,
                'number' => $note->delivery_note_number,
                'print_url' => route('branch.delivery-notes.print', $note),
                'show_url' => route('branch.delivery-notes.show', $note),
            ]);
        }

        return redirect()
            ->route('branch.delivery-notes.show', $note)
            ->with('success', 'Delivery note '.$note->delivery_note_number.' created.');
    }

    /**
     * Save an edited delivery note: what it held goes back and the new lines go out.
     */
    public function update(UpdateDeliveryNoteRequest $request, DeliveryNote $deliveryNote): JsonResponse|RedirectResponse
    {
        try {
            $note = $this->deliveryNoteService->update($deliveryNote, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
            }

            throw $e;
        } catch (Throwable $e) {
            Log::error('Delivery note update failed.', ['user_id' => $request->user()?->id, 'exception' => $e]);
            $message = 'The delivery note could not be saved. Please try again or contact support.';

            return $request->expectsJson() ? $this->jsonFailure($message, [], 500) : back()->withInput()->withErrors(['delivery_note' => $message]);
        }

        session()->flash('success', 'Delivery note '.$note->delivery_note_number.' updated.');

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Delivery note '.$note->delivery_note_number.' updated.', [
                'uuid' => $note->uuid,
                'show_url' => route('branch.delivery-notes.show', $note),
            ]);
        }

        return redirect()->route('branch.delivery-notes.show', $note);
    }

    /**
     * Delivery note detail.
     */
    public function show(DeliveryNote $deliveryNote): View
    {
        $this->authorize('view', $deliveryNote);

        return view('branch.delivery-notes.show', [
            'note' => $this->deliveryNoteService->loadDetail($deliveryNote),
            'paymentMethods' => \App\Enums\PaymentMethod::options(),
            // The note had no tax; the cashier picks it now, when it becomes an invoice.
            'taxEnabled' => (bool) setting('tax_enabled'),
            'ivaRates' => $this->taxRates->active(TaxRate::KIND_IVA)->map(fn (TaxRate $r): array => ['uuid' => $r->uuid, 'name' => $r->name, 'rate' => (float) $r->rate])->values()->all(),
            'recargoRates' => $this->taxRates->active(TaxRate::KIND_RECARGO)->map(fn (TaxRate $r): array => ['uuid' => $r->uuid, 'name' => $r->name, 'rate' => (float) $r->rate])->values()->all(),
            'defaultIvaUuid' => DocumentSettings::tax()['uuid'],
        ]);
    }

    /**
     * Print view: the client's copy, with no company details.
     */
    public function print(DeliveryNote $deliveryNote): View
    {
        $this->authorize('view', $deliveryNote);

        return view('branch.delivery-notes.print', [
            'note' => $this->deliveryNoteService->loadDetail($deliveryNote),
        ]);
    }

    /**
     * Cancel an issued note; the goods go back to stock.
     */
    public function cancel(CancelDeliveryNoteRequest $request, DeliveryNote $deliveryNote): RedirectResponse
    {
        $this->deliveryNoteService->cancel($deliveryNote, $request->validated('cancel_reason'), $request->user());

        return redirect()
            ->route('branch.delivery-notes.show', $deliveryNote)
            ->with('success', 'Delivery note cancelled. The goods are back in stock.');
    }

    /**
     * Turn an issued note into an invoice.
     */
    public function convert(ConvertDeliveryNoteRequest $request, DeliveryNote $deliveryNote): RedirectResponse
    {
        $invoice = $this->deliveryNoteService->convertToInvoice($deliveryNote, $request->validated(), $request->user());

        return redirect()
            ->route('branch.invoices.show', $invoice)
            ->with('success', 'Invoice '.$invoice->invoice_number.' created from delivery note '.$deliveryNote->delivery_note_number.'.');
    }
}