<?php

namespace App\Enums;

/**
 * PerformaStatus
 *
 * Lifecycle of a credit-issue ledger. Open has unpaid goods; partial has
 * at least one settlement with remaining qty; settled has nothing left;
 * cancelled means every outstanding line was returned (no sale).
 */
enum PerformaStatus: string
{
    case OPEN = 'open';
    case PARTIAL = 'partial';
    case SETTLED = 'settled';
    case CANCELLED = 'cancelled';

    /**
     * Human-readable label for badges and filters.
     */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::PARTIAL => 'Partial',
            self::SETTLED => 'Settled',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Whether unpaid goods can still be settled or returned.
     */
    public function isOpen(): bool
    {
        return $this === self::OPEN || $this === self::PARTIAL;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
