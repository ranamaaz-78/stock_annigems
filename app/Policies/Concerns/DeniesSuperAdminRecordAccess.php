<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * DeniesSuperAdminRecordAccess
 *
 * Shared guard for policies that protect an individual branch record
 * (invoice, quotation, jewellery piece, stone packet, customer).
 *
 * Module 6 spec section 7.4 states that SuperAdmin sees aggregate figures only
 * and must never reach a single transaction or record. Today that happens to be
 * true only by accident: the `branch.member` middleware keeps SuperAdmin off the
 * `/branch/*` routes, and the branch comparisons in these policies fail because
 * SuperAdmin has a null branch_id. Customers are worse — they are global, so
 * nothing stopped SuperAdmin at the policy layer at all.
 *
 * Accidental refusal is not refusal. This trait makes it explicit and keeps the
 * single condition in one place so it cannot drift between policies.
 */
trait DeniesSuperAdminRecordAccess
{
    /**
     * Whether this actor is allowed to reach individual branch records.
     *
     * Returns false for SuperAdmin regardless of permissions held — SuperAdmin
     * is granted every permission by design, so a permission check alone would
     * pass.
     */
    protected function mayReachRecords(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }
}
