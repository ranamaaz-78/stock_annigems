<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * JewelleryQrService
 *
 * Encodes the public product URL. The payload is /item/{qr_token}, never the
 * admin uuid.
 */
class JewelleryQrService
{
    /**
     * Absolute public product URL for a token.
     */
    public function publicUrl(?string $qrToken): string
    {
        if ($qrToken === null || $qrToken === '') {
            return '';
        }

        return url('/item/'.$qrToken);
    }

    /**
     * PNG bytes.
     */
    public function png(string $qrToken, int $size = 200): string
    {
        $result = (new Builder(
            writer: new PngWriter,
            data: $this->publicUrl($qrToken),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 8,
        ))->build();

        return $result->getString();
    }

    /**
     * Data URI for <img src>.
     */
    public function dataUri(?string $qrToken, int $size = 200): string
    {
        if ($qrToken === null || $qrToken === '') {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode($this->png($qrToken, $size));
    }

    /**
     * SVG markup for print labels.
     */
    public function svg(string $qrToken, int $size = 120): string
    {
        $result = (new Builder(
            writer: new SvgWriter,
            data: $this->publicUrl($qrToken),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 4,
        ))->build();

        return $result->getString();
    }
}
