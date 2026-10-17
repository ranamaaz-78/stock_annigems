<?php

namespace App\Policies;

use App\Models\Stone;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;

/**
 * StonePolicy
 *
 * Branch-scoped stone master records. Cross-branch access is denied here
 * (and also by the uuid binder returning 403). SuperAdmin is refused explicitly
 * — stone totals appear in aggregate reports, individual packets do not
 * (Module 6 spec 7.4).
 */
class StonePolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * List stones for the actor's branch.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.view');
    }

    /**
     * View one stone in the actor's branch.
     */
    public function view(User $user, Stone $stone): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.view')
            && (int) $user->branch_id === (int) $stone->branch_id;
    }

    /**
     * Register a new stone type.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('stones.create');
    }

    /**
     * Edit attributes (not quantity).
     */
    public function update(User $user, Stone $stone): bool
    {
        return $user->hasPermission('stones.edit')
            && (int) $user->branch_id === (int) $stone->branch_id;
    }

    /**
     * Deactivate or reactivate.
     */
    public function deactivate(User $user, Stone $stone): bool
    {
        return $user->hasPermission('stones.deactivate')
            && (int) $user->branch_id === (int) $stone->branch_id;
    }

    /**
     * Soft-delete a lot (stock written off, history kept).
     */
    public function delete(User $user, Stone $stone): bool
    {
        return $user->hasPermission('stones.delete')
            && (int) $user->branch_id === (int) $stone->branch_id;
    }

    /**
     * Print packet labels.
     */
    public function printLabel(User $user, ?Stone $stone = null): bool
    {
        if (! $user->hasPermission('stones.barcode')) {
            return false;
        }

        if ($stone === null) {
            return true;
        }

        return (int) $user->branch_id === (int) $stone->branch_id
            && $stone->hasPacketBarcode();
    }
}
