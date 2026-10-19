<?php

namespace App\Enums;

/**
 * SettingType
 *
 * Storage and cast type for one catalog key. Values are always stored as
 * text; SettingService casts them on read so a boolean never arrives as "1".
 */
enum SettingType: string
{
    case BOOLEAN = 'boolean';
    case STRING = 'string';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case JSON = 'json';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::BOOLEAN => 'Yes / no',
            self::STRING => 'Text',
            self::INTEGER => 'Whole number',
            self::DECIMAL => 'Decimal',
            self::JSON => 'List',
        });
    }
}
