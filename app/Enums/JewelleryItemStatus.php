<?php

namespace App\Enums;

/**
 * JewelleryItemStatus
 *
 * Lifecycle of one physical piece. sold, on_memo, delivered, and transferred are
 * set only by sales / performa / delivery note / transfer services — jewellery screens
 * must reject any attempt to assign them.
 */
enum JewelleryItemStatus: string
{
    case AVAILABLE = 'available';
    case RESERVED = 'reserved';
    case DAMAGED = 'damaged';
    case LOST = 'lost';
    case UNDER_REPAIR = 'under_repair';
    case SOLD = 'sold';
    case ON_MEMO = 'on_memo';
    case DELIVERED = 'delivered';
    case TRANSFERRED = 'transferred';

    /**
     * Human-readable label for badges and filters.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::AVAILABLE => 'Available',
            self::RESERVED => 'Reserved',
            self::DAMAGED => 'Damaged',
            self::LOST => 'Lost',
            self::UNDER_REPAIR => 'Under repair',
            self::SOLD => 'Sold',
            self::ON_MEMO => 'On memo',
            self::DELIVERED => 'Delivered',
            self::TRANSFERRED => 'Transferred',
        });
    }

    /**
     * Statuses operators may set from Module 4 screens.
     *
     * @return list<self>
     */
    public static function usableInThisModule(): array
    {
        return [
            self::AVAILABLE,
            self::RESERVED,
            self::DAMAGED,
            self::LOST,
            self::UNDER_REPAIR,
        ];
    }

    /**
     * Public product page shows full detail only for these.
     *
     * @return list<self>
     */
    public static function publiclyVisible(): array
    {
        return [
            self::AVAILABLE,
            self::RESERVED,
            self::UNDER_REPAIR,
        ];
    }

    /**
     * @return list<string>
     */
    public static function usableValues(): array
    {
        return array_map(fn (self $case): string => $case->value, self::usableInThisModule());
    }
}
