<?php

namespace App\Policies;

use App\Models\StoneImage;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;

/**
 * StoneImagePolicy
 *
 * The Stones image folder is branch-owned. SuperAdmin does not open
 * individual branch pictures; the same rule as stone packets.
 */
class StoneImagePolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * Open the folder.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.images');
    }

    /**
     * Stream one picture that belongs to the actor's branch.
     */
    public function view(User $user, StoneImage $image): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.images')
            && (int) $user->branch_id === (int) $image->branch_id;
    }

    /**
     * Upload into the folder.
     */
    public function create(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.images');
    }

    /**
     * Rename a picture in the actor's branch.
     */
    public function update(User $user, StoneImage $image): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.images')
            && (int) $user->branch_id === (int) $image->branch_id;
    }

    /**
     * Remove a picture and its file.
     */
    public function delete(User $user, StoneImage $image): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.images')
            && (int) $user->branch_id === (int) $image->branch_id;
    }
}
