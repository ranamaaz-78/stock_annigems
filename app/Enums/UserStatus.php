<?php

namespace App\Enums;

/**
 * UserStatus
 *
 * Inactive users cannot authenticate regardless of branch status.
 */
enum UserStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    /**
     * Human-readable label for badges and filters.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        };
    }

    /**
     * Whether the user may attempt to log in (subject to branch checks).
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
