<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;
use App\Enums\QuotationStatus;

/**
 * QuotationPolicy
 *
 * Branch-scoped quotation authorization via permissions and branch_id match.
 * SuperAdmin is refused explicitly — it sees quotation conversion rates in the
 * aggregate reports, never a single quotation (Module 6 spec 7.4).
 */
class QuotationPolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * List quotations in own branch.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('quotations.view');
    }

    /**
     * View one quotation in own branch.
     */
    public function view(User $user, Quotation $quotation): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('quotations.view')
            && $this->sameBranch($user, $quotation);
    }

    /**
     * Create quotations.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('quotations.create');
    }

    /**
     * Edit draft quotations in own branch.
     */
    public function update(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission('quotations.edit')
            && $this->sameBranch($user, $quotation)
            && $quotation->status === QuotationStatus::DRAFT;
    }

    /**
     * Cancel draft quotations.
     */
    public function cancel(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission('quotations.cancel')
            && $this->sameBranch($user, $quotation)
            && $quotation->status === QuotationStatus::DRAFT;
    }

    /**
     * Convert draft to invoice.
     */
    public function convert(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission('quotations.convert')
            && $this->sameBranch($user, $quotation)
            && $quotation->status === QuotationStatus::DRAFT;
    }

    /**
     * Branch boundary check.
     */
    private function sameBranch(User $user, Quotation $quotation): bool
    {
        return (int) $user->branch_id === (int) $quotation->branch_id;
    }
}
