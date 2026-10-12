<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;

/**
 * BranchPolicy
 *
 * SuperAdmin may manage all branches. Branch users cannot access SuperAdmin branch CRUD.
 */
class BranchPolicy
{
    /**
     * View any branches (list).
     */
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::SUPERADMIN;
    }

    /**
     * View a single branch.
     */
    public function view(User $user, Branch $branch): bool
    {
        return $user->role === UserRole::SUPERADMIN;
    }

    /**
     * Create a branch.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::SUPERADMIN;
    }

    /**
     * Update a branch.
     */
    public function update(User $user, Branch $branch): bool
    {
        return $user->role === UserRole::SUPERADMIN;
    }

    /**
     * Deactivate / activate a branch (soft status change, not hard delete).
     */
    public function toggleStatus(User $user, Branch $branch): bool
    {
        return $user->role === UserRole::SUPERADMIN;
    }
}
