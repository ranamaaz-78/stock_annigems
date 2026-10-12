<?php

namespace App\Enums;

/**
 * SettingScope
 *
 * Distinguishes a global (SuperAdmin) change from a branch-local one on
 * settings_history. There is no third scope: business rules are never
 * per-branch.
 */
enum SettingScope: string
{
    case GLOBAL = 'global';
    case BRANCH = 'branch';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::GLOBAL => 'Global',
            self::BRANCH => 'Branch',
        };
    }
}
