<?php

namespace App\Enums;

/**
 * BranchStatus
 *
 * Active branches accept inventory assignment and allow their users to log in.
 * Inactive branches block all branch-user logins.
 */
enum BranchStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    /**
     * Human-readable label for badges and filters.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        });
    }

    /**
     * Whether the branch is considered operational.
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
