<?php

namespace App\Services;

use App\Models\Invoice;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * InvoiceQrService
 *
 * Generates QR codes for the public verification URL. Uses verification_token,
 * never the invoice uuid, so printed codes cannot expose admin URLs.
 */
class InvoiceQrService
{
    /**
     * Public verification URL for an invoice.
     */
    public function verifyUrl(?string $verificationToken): string
    {
        if ($verificationToken === null || $verificationToken === '') {
            return '';
        }

        return url('/verify/'.$verificationToken);
    }

    /**
     * PNG binary for the verification QR.
     */
    public function png(string $verificationToken, int $size = 200): string
    {
        // endroid/qr-code v6 builds through the constructor; the fluent
        // Builder::create() factory was removed in that major version.
        $result = (new Builder(
            writer: new PngWriter,
            data: $this->verifyUrl($verificationToken),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 8,
        ))->build();

        return $result->getString();
    }

    /**
     * Data URI for inline img src in print views.
     */
    public function dataUri(string $verificationToken, int $size = 200): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($verificationToken, $size));
    }

    /**
     * Convenience wrapper from Invoice model.
     */
    public function forInvoice(Invoice $invoice, int $size = 200): string
    {
        if ($invoice->verification_token === null || $invoice->verification_token === '') {
            return '';
        }

        return $this->dataUri($invoice->verification_token, $size);
    }
}
