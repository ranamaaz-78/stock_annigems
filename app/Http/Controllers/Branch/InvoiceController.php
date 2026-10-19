<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\UpdateInvoiceRequest;
use App\Http\Requests\Branch\VoidInvoiceRequest;
use App\Models\Invoice;
use App\Services\InvoiceConversionService;
use App\Services\InvoiceQrService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * InvoiceController
 *
 * HTTP layer for invoice list, detail, print, and void.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private InvoiceQrService $qrService,
        private InvoiceConversionService $conversionService,
    ) {}

    /**
     * Invoice list.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        return view('branch.invoices.index', [
            'invoices' => $this->invoiceService->paginate($request->only(['status', 'from', 'to', 'payment_method', 'search'])),
            'filters' => $request->only(['status', 'from', 'to', 'payment_method', 'search']),
            'paymentMethods' => \App\Enums\PaymentMethod::options(),
        ]);
    }

    /**
     * Save an edited invoice (possible only while no payment is recorded).
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse|RedirectResponse
    {
        try {
            $invoice = $this->conversionService->updateFromWorkspace($invoice, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()], 422);
            }

            throw $e;
        } catch (Throwable $e) {
            Log::error('Invoice update failed.', ['user_id' => $request->user()?->id, 'exception' => $e]);
            $message = 'The invoice could not be saved. Please try again or contact support.';

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message, 'errors' => []], 500)
                : back()->withInput()->withErrors(['invoice' => $message]);
        }

        session()->flash('success', 'Invoice '.$invoice->invoice_number.' updated.');

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Invoice '.$invoice->invoice_number.' updated.',
                'data' => ['uuid' => $invoice->uuid, 'show_url' => route('branch.invoices.show', $invoice)],
            ]);
        }

        return redirect()->route('branch.invoices.show', $invoice);
    }

    /**
     * Invoice detail.
     */
    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice = $this->invoiceService->loadDetail($invoice);

        return view('branch.invoices.show', [
            'invoice' => $invoice,
            'qrUri' => $this->qrService->forInvoice($invoice),
            'verifyUrl' => $this->qrService->verifyUrl($invoice->verification_token),
        ]);
    }

    /**
     * Print view with verification QR.
     */
    public function print(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice = $this->invoiceService->loadDetail($invoice);

        return view('branch.invoices.print', [
            'invoice' => $invoice,
            'qrUri' => $this->qrService->forInvoice($invoice, 160),
            'verifyUrl' => $this->qrService->verifyUrl($invoice->verification_token),
        ]);
    }

    /**
     * Void invoice with mandatory reason.
     */
    public function void(VoidInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->invoiceService->void($invoice, $request->validated('void_reason'), $request->user());

        return redirect()->route('branch.invoices.show', $invoice)->with('success', 'Invoice voided.');
    }
}
