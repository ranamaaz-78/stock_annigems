<?php

namespace App\Enums;

/**
 * InvoicePrintFormat
 *
 * Paper the branch printer actually holds. Quotations stay A4; invoices may
 * be thermal at the counter.
 */
enum InvoicePrintFormat: string
{
    case A4 = 'a4';
    case THERMAL_80 = 'thermal_80mm';
    case THERMAL_58 = 'thermal_58mm';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::A4 => 'A4 sheet',
            self::THERMAL_80 => 'Thermal 80 mm',
            self::THERMAL_58 => 'Thermal 58 mm',
        };
    }
}
