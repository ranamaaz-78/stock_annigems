<?php

namespace App\Enums;

/**
 * MakingChargeType
 *
 * How making charges are calculated on a jewellery quotation/invoice line.
 */
enum MakingChargeType: string
{
    case NONE = 'none';
    case FIXED = 'fixed';
    case PER_GRAM = 'per_gram';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'None',
            self::FIXED => 'Fixed amount',
            self::PER_GRAM => 'Per gram',
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
