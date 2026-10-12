<?php

namespace App\Policies;

use App\Models\Creditor;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;

/**
 * CreditorPolicy
 *
 * Branch-scoped credit-party authorization. SuperAdmin is refused — it
 * never sees an individual creditor.
 */
class CreditorPolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * List creditors in own branch.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('creditors.view');
    }

    /**
     * View one creditor in own branch.
     */
    public function view(User $user, Creditor $creditor): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('creditors.view')
            && $this->sameBranch($user, $creditor);
    }

    /**
     * Register a new creditor on the actor's branch.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('creditors.create');
    }

    /**
     * Edit a creditor on the actor's branch.
     */
    public function update(User $user, Creditor $creditor): bool
    {
        return $user->hasPermission('creditors.edit')
            && $this->sameBranch($user, $creditor);
    }

    /**
     * Deactivate a creditor (hidden from the performa picker).
     */
    public function deactivate(User $user, Creditor $creditor): bool
    {
        return $user->hasPermission('creditors.deactivate')
            && $this->sameBranch($user, $creditor);
    }

    /**
     * Branch boundary check.
     */
    private function sameBranch(User $user, Creditor $creditor): bool
    {
        return (int) $user->branch_id === (int) $creditor->branch_id;
    }
}
