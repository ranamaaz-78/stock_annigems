<?php

namespace App\Enums;

/**
 * LabelKind
 *
 * Jewellery tags print barcode, QR, or both. Stones only ever use barcode.
 * One physical jewellery piece still has one identity — the buttons print
 * that same piece, not two different items.
 */
enum LabelKind: string
{
    case BARCODE = 'barcode';
    case QR = 'qr';
    case BOTH = 'both';

    /**
     * Button / toolbar copy.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::BARCODE => 'Barcode',
            self::QR => 'QR',
            self::BOTH => 'Barcode + QR',
        });
    }
}
