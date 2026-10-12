<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;
use App\Enums\InvoiceStatus;

/**
 * InvoicePolicy
 *
 * Branch-scoped invoice authorization. Invoices are immutable — only void.
 * SuperAdmin is refused explicitly: it may read consolidated sales figures but
 * never an individual sale (Module 6 spec 7.4).
 */
class InvoicePolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * List invoices in own branch.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('invoices.view');
    }

    /**
     * View one invoice in own branch.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('invoices.view')
            && $this->sameBranch($user, $invoice);
    }

    /**
     * Void a completed invoice.
     */
    public function void(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission('invoices.void')
            && $this->sameBranch($user, $invoice)
            && $invoice->status === InvoiceStatus::COMPLETED;
    }

    /**
     * Branch boundary check.
     */
    private function sameBranch(User $user, Invoice $invoice): bool
    {
        return (int) $user->branch_id === (int) $invoice->branch_id;
    }
}
