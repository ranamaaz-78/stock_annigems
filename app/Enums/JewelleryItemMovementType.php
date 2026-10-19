<?php

namespace App\Enums;

/**
 * JewelleryItemMovementType
 *
 * Append-only ledger on jewellery_item_movements. sold / transferred_* exist
 * for later modules; this module never writes them.
 */
enum JewelleryItemMovementType: string
{
    case CREATED = 'created';
    case STONES_CHANGED = 'stones_changed';
    case STATUS_CHANGED = 'status_changed';
    case EDITED = 'edited';
    case DAMAGED = 'damaged';
    case LOST = 'lost';
    case REPAIR_IN = 'repair_in';
    case REPAIR_OUT = 'repair_out';
    case RESERVED = 'reserved';
    case SOLD = 'sold';
    case SALE_VOIDED = 'sale_voided';
    case ISSUED_ON_CREDIT = 'issued_on_credit';
    case RETURNED_FROM_CREDIT = 'returned_from_credit';
    case DELIVERED = 'delivered';
    case RETURNED_FROM_DELIVERY = 'returned_from_delivery';
    case TRANSFERRED_IN = 'transferred_in';
    case TRANSFERRED_OUT = 'transferred_out';

    /**
     * Human-readable label for the item timeline.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::CREATED => 'Created',
            self::STONES_CHANGED => 'Stones changed',
            self::STATUS_CHANGED => 'Status changed',
            self::EDITED => 'Edited',
            self::DAMAGED => 'Marked damaged',
            self::LOST => 'Marked lost',
            self::REPAIR_IN => 'Sent for repair',
            self::REPAIR_OUT => 'Returned from repair',
            self::RESERVED => 'Reserved',
            self::SOLD => 'Sold',
            self::SALE_VOIDED => 'Sale voided',
            self::ISSUED_ON_CREDIT => 'Issued on credit',
            self::RETURNED_FROM_CREDIT => 'Returned from credit',
            self::DELIVERED => 'Delivered on delivery note',
            self::RETURNED_FROM_DELIVERY => 'Returned from delivery note',
            self::TRANSFERRED_IN => 'Transferred in',
            self::TRANSFERRED_OUT => 'Transferred out',
        });
    }
}
