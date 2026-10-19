<?php

namespace App\Enums;

/**
 * StoneOrigin
 *
 * How the stone was produced. Required on every registered stone type.
 */
enum StoneOrigin: string
{
    case NATURAL = 'natural';
    case LAB_GROWN = 'lab_grown';
    case SYNTHETIC = 'synthetic';
    case UNKNOWN = 'unknown';

    /**
     * Human-readable label for forms and tables.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::NATURAL => 'Natural',
            self::LAB_GROWN => 'Lab grown',
            self::SYNTHETIC => 'Synthetic',
            self::UNKNOWN => 'Unknown',
        });
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
