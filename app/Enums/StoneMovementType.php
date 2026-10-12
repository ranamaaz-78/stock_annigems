<?php

namespace App\Enums;

/**
 * StoneMovementType
 *
 * Ledger types for stone_stock_movements. INTAKE is written by Module 3.
 * USED_IN_ITEM / RETURNED_FROM_ITEM are written by jewellery allocation
 * (Module 4). RESERVED_ON_QUOTATION / RELEASED_FROM_QUOTATION hold loose
 * stone qty on a draft quotation so POS cannot sell the same packet twice.
 */
enum StoneMovementType: string
{
    case INTAKE = 'intake';
    case USED_IN_ITEM = 'used_in_item';
    case SOLD = 'sold';
    case DAMAGED = 'damaged';
    case LOST = 'lost';
    case RETURNED = 'returned';
    case RETURNED_FROM_ITEM = 'returned_from_item';
    case RETURNED_FROM_SALE = 'returned_from_sale';
    case ISSUED_ON_CREDIT = 'issued_on_credit';
    case SETTLED_FROM_CREDIT = 'settled_from_credit';
    case RETURNED_FROM_CREDIT = 'returned_from_credit';
    case ADJUSTED = 'adjusted';
    case TRANSFERRED_IN = 'transferred_in';
    case TRANSFERRED_OUT = 'transferred_out';
    case RESERVED_ON_QUOTATION = 'reserved_on_quotation';
    case RELEASED_FROM_QUOTATION = 'released_from_quotation';

    /**
     * Human-readable label for the movement ledger.
     */
    public function label(): string
    {
        return match ($this) {
            self::INTAKE => 'Intake',
            self::USED_IN_ITEM => 'Used in item',
            self::SOLD => 'Sold',
            self::DAMAGED => 'Damaged',
            self::LOST => 'Lost',
            self::RETURNED => 'Returned',
            self::RETURNED_FROM_ITEM => 'Returned from item',
            self::RETURNED_FROM_SALE => 'Returned from sale',
            self::ISSUED_ON_CREDIT => 'Issued on credit',
            self::SETTLED_FROM_CREDIT => 'Settled from credit',
            self::RETURNED_FROM_CREDIT => 'Returned from credit',
            self::ADJUSTED => 'Adjusted',
            self::TRANSFERRED_IN => 'Transferred in',
            self::TRANSFERRED_OUT => 'Transferred out',
            self::RESERVED_ON_QUOTATION => 'Reserved on quotation',
            self::RELEASED_FROM_QUOTATION => 'Released from quotation',
        };
    }

    /**
     * Whether this type increases on-hand quantity.
     */
    public function increasesStock(): bool
    {
        return in_array($this, [
            self::INTAKE,
            self::RETURNED,
            self::RETURNED_FROM_ITEM,
            self::RETURNED_FROM_SALE,
            self::RETURNED_FROM_CREDIT,
            self::TRANSFERRED_IN,
            self::RELEASED_FROM_QUOTATION,
        ], true);
    }
}
