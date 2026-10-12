<?php

namespace App\Enums;

/**
 * LineType
 *
 * Distinguishes jewellery piece lines from loose stone lines on quotations
 * and invoices.
 */
enum LineType: string
{
    case JEWELLERY_ITEM = 'jewellery_item';
    case LOOSE_STONE = 'loose_stone';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::JEWELLERY_ITEM => 'Jewellery item',
            self::LOOSE_STONE => 'Loose stone',
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
