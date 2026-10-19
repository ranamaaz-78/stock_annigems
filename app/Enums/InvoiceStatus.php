<?php

namespace App\Enums;

/**
 * InvoiceStatus
 *
 * Finalised sale state. Corrections are made by voiding, never by editing.
 */
enum InvoiceStatus: string
{
    case COMPLETED = 'completed';
    case VOIDED = 'voided';

    /**
     * Human-readable label for badges and filters.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::COMPLETED => 'Completed',
            self::VOIDED => 'Voided',
        });
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
