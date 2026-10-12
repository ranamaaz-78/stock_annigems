<?php

namespace App\Policies;

use App\Models\GoldRate;
use App\Models\User;

/**
 * GoldRatePolicy
 *
 * Gold rates are global. SuperAdmin may manage; branch staff may view only.
 */
class GoldRatePolicy
{
    /**
     * List rates and history.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('gold_rates.view') || $user->hasPermission('gold_rates.manage');
    }

    /**
     * View a single rate row.
     */
    public function view(User $user, GoldRate $goldRate): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Insert new rate rows — SuperAdmin only (also hard-gated in PermissionService).
     */
    public function manage(User $user): bool
    {
        return $user->hasPermission('gold_rates.manage');
    }
}
