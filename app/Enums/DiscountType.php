<?php

namespace App\Enums;

/**
 * DiscountType
 *
 * Document-level discount applied before tax.
 */
enum DiscountType: string
{
    case NONE = 'none';
    case FIXED = 'fixed';
    case PERCENTAGE = 'percentage';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'None',
            self::FIXED => 'Fixed amount',
            self::PERCENTAGE => 'Percentage',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
