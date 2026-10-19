<?php

namespace App\Enums;

/**
 * StoneStatus
 *
 * Lot state. PENDING rows are recorded but are not costed or sellable until
 * the missing figures arrive. IN_STOCK and OUT_OF_STOCK follow the stock on
 * hand automatically (Stone::syncStockStatus), so a lot that runs out reads
 * "Out of stock" and goes back to "In stock" when it is restocked.
 */
enum StoneStatus: string
{
    case IN_STOCK = 'in_stock';
    case OUT_OF_STOCK = 'out_of_stock';
    case PENDING = 'pending';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::IN_STOCK => 'In stock',
            self::OUT_OF_STOCK => 'Out of stock',
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
     * What an operator may choose on the lot form. "Out of stock" is never
     * chosen: it follows the stock on hand by itself.
     *
     * @return array<string, string> value => label
     */
    public static function formOptions(): array
    {
        return array_diff_key(self::options(), [self::OUT_OF_STOCK->value => true]);
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
