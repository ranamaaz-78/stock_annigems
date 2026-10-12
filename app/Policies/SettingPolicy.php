<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * SettingPolicy
 *
 * Role is the access gate for every settings screen. Permission names
 * exist for the Roles UI and for audit, but SuperAdmin / Admin / Manager
 * must not be locked out of the screens they own just because a shop has
 * not re-seeded the new settings.* rows.
 *
 * SuperAdmin owns global writes and history. Admin owns branch writes and
 * may read the global mirror. Manager may view branch print preferences
 * (they need to know the label format) but cannot change them. Cashier
 * and Inventory Staff have no settings access at all.
 */
class SettingPolicy
{
    /**
     * View the SuperAdmin global settings screen, or the Admin read-only mirror.
     *
     * Role is the gate. Permission rows are seeded for the Roles UI, but a
     * shop that has not re-seeded must not lock SuperAdmin or Admin out of
     * the screens they own.
     */
    public function viewGlobalSettings(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isAdmin() && $user->branch_id !== null;
    }

    /**
     * Write a global settings group. SuperAdmin only — role is the gate.
     */
    public function manageGlobalSettings(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * View the SuperAdmin change-history table.
     */
    public function viewSettingsHistory(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * View branch settings for the actor's own branch.
     *
     * Admin and Manager. Cashier / Inventory Staff never reach this screen.
     */
    public function viewBranchSettings(User $user): bool
    {
        return in_array($user->role, [UserRole::ADMIN, UserRole::MANAGER], true)
            && $user->branch_id !== null;
    }

    /**
     * Write branch settings for the actor's own branch. Admin only.
     */
    public function manageBranchSettings(User $user): bool
    {
        return $user->isAdmin() && $user->branch_id !== null;
    }
}
