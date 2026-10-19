<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\RecordPaymentRequest;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;

/**
 * PaymentController
 *
 * Records the payment of a pending invoice or delivery note from its page.
 * The rules live in PaymentService.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    /**
     * Pay a pending invoice.
     */
    public function invoice(RecordPaymentRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->payments->record($invoice, $request->validated('payment_method'), $request->validated('payment_reference'), $request->user());

        return redirect()->route('branch.invoices.show', $invoice)->with('success', __('Payment recorded.'));
    }

    /**
     * Pay an issued delivery note.
     */
    public function deliveryNote(RecordPaymentRequest $request, DeliveryNote $deliveryNote): RedirectResponse
    {
        $this->payments->record($deliveryNote, $request->validated('payment_method'), $request->validated('payment_reference'), $request->user());

        return redirect()->route('branch.delivery-notes.show', $deliveryNote)->with('success', __('Payment recorded.'));
    }
}
