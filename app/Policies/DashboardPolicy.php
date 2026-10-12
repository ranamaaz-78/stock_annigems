<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * DashboardPolicy
 *
 * Controls visibility of individual dashboard cards.
 *
 * Every card is decided here and in DashboardStatsService — server-side — and a
 * card the actor may not see is never built at all. Hiding a rendered figure in
 * Blade would still have computed and shipped it.
 *
 * There is deliberately no inventory-value or profit ability. The system records
 * no purchase cost, so neither figure can be produced honestly, and leaving a
 * gate for one would invite someone to fill it with a proxy later.
 */
class DashboardPolicy
{
    /**
     * Access the branch dashboard (any branch member).
     */
    public function viewBranch(User $user): bool
    {
        return ! $user->isSuperAdmin() && $user->branch_id !== null;
    }

    /**
     * View sales-oriented cards (revenue, invoice counts, pieces sold).
     *
     * Inventory Staff is excluded by design: that role has no sales visibility
     * at all, per spec 8.5.
     */
    public function viewSalesKpis(User $user): bool
    {
        if ($user->role === UserRole::INVENTORY_STAFF) {
            return false;
        }

        return $user->hasPermission('pos.access')
            || $user->hasPermission('invoices.view')
            || in_array($user->role, [UserRole::ADMIN, UserRole::MANAGER], true);
    }

    /**
     * View inventory-count cards (available pieces, gold weight in stock,
     * aging stock). Counts and weights only — never a valuation.
     */
    public function viewInventoryKpis(User $user): bool
    {
        return $user->hasPermission('jewellery.view')
            || $user->hasPermission('items.view');
    }
}
