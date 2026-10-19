<?php

namespace App\Enums;

/**
 * PaymentStatus
 *
 * Whether the money for an invoice or delivery note has been recorded. A
 * document is pending until a payment is recorded against it; while it is
 * pending it can still be edited.
 */
enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PAID => 'Paid',
        };
    }
}
