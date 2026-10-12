<?php

namespace App\Policies;

use App\Models\StoneIntake;
use App\Models\User;

/**
 * StoneIntakePolicy
 *
 * Intakes are immutable. There is no update/delete ability.
 */
class StoneIntakePolicy
{
    /**
     * Scan / save a new batch.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('stones.intake');
    }

    /**
     * View intake history.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('stones.intake_view');
    }

    /**
     * View one intake in the actor's branch.
     */
    public function view(User $user, StoneIntake $intake): bool
    {
        return $user->hasPermission('stones.intake_view')
            && (int) $user->branch_id === (int) $intake->branch_id;
    }
}
