<?php

namespace App\Support\Settings;

use App\Enums\SettingType;

/**
 * SettingCaster
 *
 * Converts between the text column and the typed value SettingService
 * returns. A boolean setting must never reach a caller as the string "1".
 */
class SettingCaster
{
    /**
     * Decode a stored string into the catalog type.
     */
    public static function decode(mixed $stored, SettingType $type): mixed
    {
        if ($stored === null || $stored === '') {
            return match ($type) {
                SettingType::BOOLEAN => false,
                SettingType::INTEGER => 0,
                SettingType::DECIMAL => 0.0,
                SettingType::JSON => [],
                SettingType::STRING => '',
            };
        }

        return match ($type) {
            SettingType::BOOLEAN => filter_var($stored, FILTER_VALIDATE_BOOLEAN),
            SettingType::INTEGER => (int) $stored,
            SettingType::DECIMAL => (float) $stored,
            SettingType::JSON => is_array($stored) ? $stored : (json_decode((string) $stored, true) ?: []),
            SettingType::STRING => (string) $stored,
        };
    }

    /**
     * Encode a typed value for the text column.
     */
    public static function encode(mixed $value, SettingType $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            SettingType::BOOLEAN => $value ? '1' : '0',
            SettingType::INTEGER => (string) (int) $value,
            SettingType::DECIMAL => (string) $value,
            SettingType::JSON => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR),
            SettingType::STRING => (string) $value,
        };
    }
}
