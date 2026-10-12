<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;

/**
 * CustomerPolicy
 *
 * Customers are global — authorization is permission-based only, never
 * branch-scoped.
 *
 * Because customers carry no branch_id, the incidental branch mismatch that
 * keeps SuperAdmin out of the other record types does not apply here. The
 * explicit refusal below is the only thing preventing SuperAdmin from reading a
 * customer's purchase history, which Module 6 spec 7.4 forbids.
 */
class CustomerPolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * List and search customers.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('customers.view');
    }

    /**
     * View customer profile and cross-branch history.
     */
    public function view(User $user, Customer $customer): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('customers.view');
    }

    /**
     * Register a new customer.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('customers.create');
    }

    /**
     * Edit any customer record.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customers.edit');
    }

    /**
     * Deactivate a customer.
     */
    public function deactivate(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customers.deactivate');
    }
}
