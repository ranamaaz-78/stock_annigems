<?php

namespace App\Enums;

/**
 * StoneStatus
 *
 * Lot state from the supplier sheet. PENDING rows are recorded but are not
 * costed or sellable until the missing figures arrive.
 */
enum StoneStatus: string
{
    case IN_STOCK = 'in_stock';
    case PENDING = 'pending';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::IN_STOCK => 'In stock',
            self::PENDING => 'Pending',
        };
    }

    /**
     * Sheet STATUS text mapped to a case. Anything unrecognised is pending
     * rather than assumed sellable.
     */
    public static function fromSheet(?string $value): self
    {
        $key = strtoupper(trim((string) $value));

        return match ($key) {
            'IN STOCK', 'INSTOCK', 'IN-STOCK' => self::IN_STOCK,
            default => self::PENDING,
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
