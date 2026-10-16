<?php

namespace App\Policies;

use App\Models\Performa;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;

/**
 * PerformaPolicy
 *
 * Branch-scoped credit-ledger authorization. SuperAdmin is refused — it
 * never sees an individual performa.
 */
class PerformaPolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * List performas in own branch.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('performas.view');
    }

    /**
     * View one performa in own branch.
     */
    public function view(User $user, Performa $performa): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('performas.view')
            && $this->sameBranch($user, $performa);
    }

    /**
     * Issue a performa.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('performas.create');
    }

    /**
     * Edit an open performa (same permission as issuing one).
     */
    public function update(User $user, Performa $performa): bool
    {
        return $user->hasPermission('performas.create')
            && $this->sameBranch($user, $performa)
            && $performa->isOpen();
    }

    /**
     * Take payment against unpaid lines.
     */
    public function settle(User $user, Performa $performa): bool
    {
        return $user->hasPermission('performas.settle')
            && $this->sameBranch($user, $performa)
            && $performa->isOpen();
    }

    /**
     * Return unpaid goods to stock.
     */
    public function returnUnpaid(User $user, Performa $performa): bool
    {
        return $user->hasPermission('performas.return')
            && $this->sameBranch($user, $performa)
            && $performa->isOpen();
    }

    /**
     * Branch boundary check.
     */
    private function sameBranch(User $user, Performa $performa): bool
    {
        return (int) $user->branch_id === (int) $performa->branch_id;
    }
}
