<?php

namespace App\Enums;

/**
 * DeliveryNoteStatus
 *
 * A delivery note is issued once and then either stays issued, is cancelled
 * (goods back on the shelf) or is invoiced (goods sold, note closed).
 */
enum DeliveryNoteStatus: string
{
    case ISSUED = 'issued';
    case INVOICED = 'invoiced';
    case CANCELLED = 'cancelled';

    /**
     * Human-readable label for badges and filters.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::ISSUED => 'Issued',
            self::INVOICED => 'Invoiced',
            self::CANCELLED => 'Cancelled',
        });
    }

    /**
     * Whether the goods are still out with the client and the note can change.
     */
    public function isOpen(): bool
    {
        return $this === self::ISSUED;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
