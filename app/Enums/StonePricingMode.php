<?php

namespace App\Enums;

/**
 * StonePricingMode
 *
 * How a stone lot is priced on the supplier sheet. The mode decides which
 * fields are required and which formula StonePricingService applies, so it
 * must never be a free-text string.
 */
enum StonePricingMode: string
{
    case PER_CARAT = 'per_carat';
    case PER_PIECE = 'per_piece';
    case PER_GRAM = 'per_gram';

    /**
     * Human-readable label for forms and tables.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::PER_CARAT => 'Per carat price',
            self::PER_PIECE => 'Per piece price',
            self::PER_GRAM => 'Per gram price',
        });
    }

    /**
     * Sheet MEASUREMENT text mapped to a mode, or null when the cell holds
     * something else (PENDING, NO COUNT, a note). Unknown text must not
     * silently become a price.
     */
    public static function fromSheet(?string $value): ?self
    {
        $key = strtoupper(trim((string) $value));

        return match ($key) {
            'PER CARAT PRICE', 'PER CT PRICE', 'PER CARAT' => self::PER_CARAT,
            'PER PIECE PRICE', 'PER PC PRICE', 'PER PIECE' => self::PER_PIECE,
            'PER GMS PRICE', 'PER GM PRICE', 'PER GRAM PRICE', 'PER GRAM' => self::PER_GRAM,
            default => null,
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
