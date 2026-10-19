<?php

namespace App\Enums;

/**
 * QuotationStatus
 *
 * Lifecycle of a branch quotation. Converted quotations become read-only;
 * cancelled quotations cannot be converted.
 */
enum QuotationStatus: string
{
    case DRAFT = 'draft';
    case CONVERTED = 'converted';
    case CANCELLED = 'cancelled';

    /**
     * Human-readable label for badges and filters.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::DRAFT => 'Draft',
            self::CONVERTED => 'Converted',
            self::CANCELLED => 'Cancelled',
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
