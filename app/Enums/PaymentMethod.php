<?php

namespace App\Enums;

/**
 * PaymentMethod
 *
 * Full payment method recorded on invoice conversion.
 */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case CARD = 'card';
    case BANK_TRANSFER = 'bank_transfer';
    case CHEQUE = 'cheque';
    case OTHER = 'other';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::CARD => 'Card',
            self::BANK_TRANSFER => 'Bank transfer',
            self::CHEQUE => 'Cheque',
            self::OTHER => 'Other',
        };
    }

    /**
     * @return list<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
