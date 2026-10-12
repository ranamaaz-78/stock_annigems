<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * UserPolicy
 *
 * SuperAdmin may list all users, create Branch Admins, and activate/deactivate
 * or reset passwords. Branch Admins manage staff in their own branch via
 * permission checks — never role name comparisons alone.
 */
class UserPolicy
{
    /**
     * View any users (SuperAdmin cross-branch or branch staff list).
     */
    public function viewAny(User $user): bool
    {
        if ($user->role === UserRole::SUPERADMIN) {
            return true;
        }

        return $user->hasPermission('users.view');
    }

    /**
     * View a specific user.
     */
    public function view(User $user, User $model): bool
    {
        if ($user->role === UserRole::SUPERADMIN) {
            return true;
        }

        if (! $user->hasPermission('users.view')) {
            return false;
        }

        return $user->branch_id === $model->branch_id;
    }

    /**
     * Create a Branch Admin (SuperAdmin only).
     */
    public function createBranchAdmin(User $user): bool
    {
        return $user->role === UserRole::SUPERADMIN;
    }

    /**
     * Create branch staff (Manager, Cashier, Inventory Staff).
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() && $user->hasPermission('users.create');
    }

    /**
     * Update a user (not self for role/permissions/status).
     */
    public function update(User $actor, User $model): bool
    {
        if ($actor->role === UserRole::SUPERADMIN) {
            return $actor->id !== $model->id && $model->role !== UserRole::SUPERADMIN;
        }

        if ($actor->id === $model->id) {
            return false;
        }

        if (! $actor->hasPermission('users.edit')) {
            return false;
        }

        return $actor->branch_id === $model->branch_id
            && $model->role !== UserRole::ADMIN;
    }

    /**
     * Activate or deactivate a user (never the acting user themselves).
     */
    public function toggleStatus(User $actor, User $model): bool
    {
        if ($actor->role === UserRole::SUPERADMIN) {
            return $actor->id !== $model->id && $model->role !== UserRole::SUPERADMIN;
        }

        if ($actor->id === $model->id) {
            return false;
        }

        if (! $actor->hasPermission('users.deactivate')) {
            return false;
        }

        return $actor->branch_id === $model->branch_id
            && $model->role !== UserRole::ADMIN;
    }

    /**
     * Log in as another account (Super Admin only, never as another Super
     * Admin or oneself). Whether the account is active is reported to the
     * operator by LoginAsService, not hidden behind a 403.
     */
    public function loginAs(User $actor, User $model): bool
    {
        return $actor->role === UserRole::SUPERADMIN
            && $actor->id !== $model->id
            && $model->role !== UserRole::SUPERADMIN;
    }

    /**
     * Reset another user's password.
     */
    public function resetPassword(User $actor, User $model): bool
    {
        if ($actor->role === UserRole::SUPERADMIN) {
            return $actor->id !== $model->id && $model->role !== UserRole::SUPERADMIN;
        }

        if ($actor->id === $model->id) {
            return false;
        }

        if (! $actor->hasPermission('users.edit')) {
            return false;
        }

        return $actor->branch_id === $model->branch_id
            && $model->role !== UserRole::ADMIN;
    }

    /**
     * View the read-only roles & permissions reference screen.
     */
    public function viewRoleReference(User $user): bool
    {
        return $user->isAdmin() && $user->hasPermission('users.view');
    }
}
