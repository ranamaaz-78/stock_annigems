<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * ReportPolicy
 *
 * Single authorization surface for every Module 6 report screen and export.
 *
 * Two independent layers guard each ability, as required by the module spec:
 *   1. the granular permission, and
 *   2. an explicit role assertion.
 *
 * The role assertion is not redundant. PermissionService::effectivePermissionNames()
 * returns *every* permission for a SuperAdmin and skips the hard gates entirely,
 * so a permission check alone would let SuperAdmin into branch-detail reports —
 * which the spec forbids because those reports expose individual transactions.
 * Conversely a branch Admin holds no cross-branch rights, so the SuperAdmin
 * abilities assert their own role too.
 */
class ReportPolicy
{
    /**
     * View the branch-level (record-detail) report screens.
     *
     * Admin of one branch only. Manager, Cashier and Inventory Staff are
     * refused, and so is SuperAdmin.
     */
    public function viewAdminReports(User $user): bool
    {
        return $user->role === UserRole::ADMIN
            && $user->branch_id !== null
            && $user->hasPermission('reports.view');
    }

    /**
     * Export a branch-level report.
     *
     * Gated identically to viewing, plus the separate export permission, so an
     * export can never leak data a user could not see on screen.
     */
    public function exportAdminReports(User $user): bool
    {
        return $this->viewAdminReports($user)
            && $user->hasPermission('reports.export');
    }

    /**
     * View the SuperAdmin aggregate-only reports.
     *
     * These never return a row-level record; see App\Services\Reports\SuperAdmin.
     */
    public function viewSuperAdminReports(User $user): bool
    {
        return $user->isSuperAdmin()
            && $user->hasPermission('reports.superadmin');
    }

    /**
     * Export a SuperAdmin aggregate report.
     */
    public function exportSuperAdminReports(User $user): bool
    {
        return $this->viewSuperAdminReports($user);
    }

    /**
     * View the dashboard. Everyone signed in may; the card content is what
     * varies by role, and each card is permission-checked separately.
     */
    public function viewDashboard(User $user): bool
    {
        return $user->hasPermission('dashboard.view') || $user->isSuperAdmin();
    }
}
