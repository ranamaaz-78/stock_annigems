<?php

namespace App\Policies;

use App\Models\JewelleryItem;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;

/**
 * JewelleryItemPolicy
 *
 * Branch-scoped pieces. Allocating stones uses jewellery.create/edit, not
 * stones.view — the operator is building a piece, not managing stone stock.
 * SuperAdmin is refused explicitly: stock distribution is visible in aggregate,
 * individual pieces are not (Module 6 spec 7.4).
 */
class JewelleryItemPolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * List items for the actor's branch.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('jewellery.view');
    }

    /**
     * View one item in the actor's branch.
     */
    public function view(User $user, JewelleryItem $item): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('jewellery.view')
            && (int) $user->branch_id === (int) $item->branch_id;
    }

    /**
     * Register a new piece (includes stone allocation).
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('jewellery.create');
    }

    /**
     * Edit a piece (includes stone allocation deltas).
     */
    public function update(User $user, JewelleryItem $item): bool
    {
        return $user->hasPermission('jewellery.edit')
            && (int) $user->branch_id === (int) $item->branch_id;
    }

    /**
     * Soft-delete (returns stones to stock).
     */
    public function delete(User $user, JewelleryItem $item): bool
    {
        return $user->hasPermission('jewellery.delete')
            && (int) $user->branch_id === (int) $item->branch_id;
    }

    /**
     * Print labels.
     */
    public function printLabel(User $user, ?JewelleryItem $item = null): bool
    {
        if (! $user->hasPermission('jewellery.barcode')) {
            return false;
        }

        if ($item === null) {
            return true;
        }

        return (int) $user->branch_id === (int) $item->branch_id;
    }

    /**
     * Change lifecycle status.
     */
    public function changeStatus(User $user, JewelleryItem $item): bool
    {
        return $user->hasPermission('jewellery.status')
            && (int) $user->branch_id === (int) $item->branch_id;
    }
}
