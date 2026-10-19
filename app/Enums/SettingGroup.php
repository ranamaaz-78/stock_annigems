<?php

namespace App\Enums;

/**
 * SettingGroup
 *
 * One save action per group. A mistake in Tax cannot silently rewrite Locale.
 */
enum SettingGroup: string
{
    case COMPANY = 'company';
    case FEATURES = 'features';
    case TAX = 'tax';
    case DISCOUNT = 'discount';
    case NUMBERING = 'numbering';
    case LOCALE = 'locale';
    case CONTACT = 'contact';
    case PRINT = 'print';
    case INVOICE = 'invoice';

    /**
     * Human-readable card title.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::COMPANY => 'Company / Brand',
            self::FEATURES => 'Feature Toggles',
            self::TAX => 'Tax',
            self::DISCOUNT => 'Discount',
            self::NUMBERING => 'Document Numbering',
            self::LOCALE => 'Currency & Locale',
            self::CONTACT => 'Branch Contact',
            self::PRINT => 'Print Preferences',
            self::INVOICE => 'Document Footers',
        });
    }

    /**
     * Material icon for the group card header.
     */
    public function icon(): string
    {
        return match ($this) {
            self::COMPANY => 'apartment',
            self::FEATURES => 'toggle_on',
            self::TAX => 'receipt_long',
            self::DISCOUNT => 'sell',
            self::NUMBERING => 'tag',
            self::LOCALE => 'public',
            self::CONTACT => 'store',
            self::PRINT => 'print',
            self::INVOICE => 'notes',
        };
    }

    /**
     * Groups SuperAdmin may save on the global settings table.
     *
     * @return list<self>
     */
    public static function globalGroups(): array
    {
        return [
            self::COMPANY,
            self::FEATURES,
            self::TAX,
            self::DISCOUNT,
            self::NUMBERING,
            self::LOCALE,
        ];
    }
}
