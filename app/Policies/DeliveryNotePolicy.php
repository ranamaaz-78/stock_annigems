<?php

namespace App\Policies;

use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;

/**
 * DeliveryNotePolicy
 *
 * Branch-scoped delivery note authorization. A note is immutable: it can only
 * be cancelled or converted to an invoice while it is still issued.
 * SuperAdmin is refused explicitly, like every individual branch record.
 */
class DeliveryNotePolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * List delivery notes in own branch.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('delivery_notes.view');
    }

    /**
     * View or print one delivery note in own branch.
     */
    public function view(User $user, DeliveryNote $note): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('delivery_notes.view')
            && $this->sameBranch($user, $note);
    }

    /**
     * Issue a delivery note from the POS.
     */
    public function create(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('delivery_notes.create');
    }

    /**
     * Cancel an issued note and return its goods.
     */
    public function cancel(User $user, DeliveryNote $note): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('delivery_notes.cancel')
            && $this->sameBranch($user, $note)
            && $note->status === DeliveryNoteStatus::ISSUED;
    }

    /**
     * Turn an issued note into an invoice.
     */
    public function convert(User $user, DeliveryNote $note): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('delivery_notes.convert')
            && $user->hasPermission('invoices.view')
            && $this->sameBranch($user, $note)
            && $note->status === DeliveryNoteStatus::ISSUED;
    }

    /**
     * Record the payment of an issued note.
     */
    public function recordPayment(User $user, DeliveryNote $note): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('delivery_notes.create')
            && $this->sameBranch($user, $note)
            && $note->status === DeliveryNoteStatus::ISSUED
            && ! $note->isPaid();
    }

    /**
     * Edit a note, which is possible only until a payment is recorded.
     */
    public function update(User $user, DeliveryNote $note): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('delivery_notes.create')
            && $this->sameBranch($user, $note)
            && $note->canBeEdited();
    }

    private function sameBranch(User $user, DeliveryNote $note): bool
    {
        return (int) $user->branch_id === (int) $note->branch_id;
    }
}