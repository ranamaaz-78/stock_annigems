<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\VoidInvoiceRequest;
use App\Models\Invoice;
use App\Services\InvoiceQrService;
use App\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
