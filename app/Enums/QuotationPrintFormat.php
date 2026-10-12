<?php

namespace App\Enums;

/**
 * QuotationPrintFormat
 *
 * Quotations are estimates, not till receipts, so A4 is the only permitted
 * format. The enum exists so a future thermal option is a catalog change,
 * not a free string.
 */
enum QuotationPrintFormat: string
{
    case A4 = 'a4';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::A4 => 'A4 sheet',
        };
    }
}
