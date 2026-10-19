<?php

namespace App\Policies;

use App\Models\StoneCategory;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;

/**
 * StoneCategoryPolicy
 *
 * Renaming or deleting a material folder is a branch-admin action. Viewing
 * folders stays with StonePolicy::viewAny, since a folder is just a way of
 * browsing lots.
 */
class StoneCategoryPolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * Rename or delete a folder of the actor's own branch.
     */
    public function manage(User $user, StoneCategory $category): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.folders')
            && (int) $user->branch_id === (int) $category->branch_id;
    }
}
