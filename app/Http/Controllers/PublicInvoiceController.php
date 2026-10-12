<?php

namespace App\Http\Controllers;

use App\Services\InvoiceVerificationService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\View\View;

/**
 * PublicInvoiceController
 *
 * Unauthenticated invoice verification at /verify/{token}. Uses
 * verification_token only — never the invoice uuid.
 */
class PublicInvoiceController extends Controller
{
    public function __construct(
        private InvoiceVerificationService $verificationService,
    ) {}

    /**
     * Public verification page.
     */
    public function show(string $token): View
    {
        $invoice = $this->verificationService->findByToken($token);

        if (! $invoice) {
            throw new NotFoundHttpException;
        }

        return view('public.invoice-verify', [
            'payload' => $this->verificationService->publicPayload($invoice),
            'token' => $token,
        ]);
    }
}
