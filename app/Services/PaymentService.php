<?php

namespace App\Services;

use App\Enums\DeliveryNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PaymentService
 *
 * Records the money for an invoice or a delivery note. Until a payment is
 * recorded the document is pending and can still be edited; recording one
 * locks it. A document is paid once its payments cover the total.
 */
class PaymentService
{
    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    /**
     * Record a payment against an invoice or delivery note.
     *
     * The amount is the balance still due unless one is given. Called inside
     * the caller's transaction when the document is being created, and on its
     * own when a pending document is paid later.
     */
    public function record(
        Invoice|DeliveryNote $payable,
        string $method,
        ?string $reference,
        User $actor,
        ?float $amount = null,
        ?string $note = null,
        ?string $paidOn = null,
    ): Payment {
        return DB::transaction(function () use ($payable, $method, $reference, $actor, $amount, $note, $paidOn): Payment {
            $payable = $payable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();

            if ((int) $payable->branch_id !== (int) $actor->branch_id) {
                abort(403);
            }

            $this->assertPayable($payable);

            $due = $payable->balanceDue();
            $amount = round($amount ?? $due, 2);

            if ($amount <= 0 || $amount > $due + 0.005) {
                throw ValidationException::withMessages([
                    'payment' => $due <= 0
                        ? 'Nothing is left to pay on this document.'
                        : 'The amount must be above zero and no more than the balance due.',
                ]);
            }

            $payment = Payment::query()->create([
                'branch_id' => $payable->branch_id,
                'payable_type' => $payable::class,
                'payable_id' => $payable->id,
                'amount' => $amount,
                'payment_method' => PaymentMethod::from($method),
                'payment_reference' => filled($reference) ? $reference : null,
                'paid_on' => $paidOn ?? now()->toDateString(),
                'note' => $note,
            ]);

            $payable->unsetRelation('payments');
            $paid = $payable->balanceDue() <= 0.005;

            $changes = ['payment_status' => $paid ? PaymentStatus::PAID : PaymentStatus::PENDING];
            if ($payable instanceof Invoice) {
                // The invoice keeps the method of its latest payment for lists and reports.
                $changes['payment_method'] = PaymentMethod::from($method);
                $changes['payment_reference'] = filled($reference) ? $reference : null;
            }
            $payable->update($changes);

            $this->auditLog->log('payment.recorded', $payable, null, [
                'amount' => (string) $payment->amount,
                'method' => $method,
                'document' => $payable instanceof Invoice ? $payable->invoice_number : $payable->delivery_note_number,
            ], $actor);

            DashboardStatsService::forgetBranch((int) $payable->branch_id);

            return $payment;
        });
    }

    /**
     * Apply the payment choice made when a document is created: "paid" records
     * the full balance with the method given, "pending" records nothing.
     *
     * @param  array<string, mixed>  $choice  payment_status, payment_method, payment_reference
     */
    public function applyChoice(Invoice|DeliveryNote $payable, array $choice, User $actor): void
    {
        $status = (string) ($choice['payment_status'] ?? PaymentStatus::PAID->value);

        if ($status !== PaymentStatus::PAID->value) {
            return;
        }

        $this->record($payable, (string) $choice['payment_method'], $choice['payment_reference'] ?? null, $actor);
    }

    /**
     * Carry a delivery note's payments over to the invoice made from it, so the
     * invoice shows everything that was paid. The note keeps its own record.
     */
    public function copyPayments(DeliveryNote $note, Invoice $invoice): float
    {
        $copied = 0.0;

        foreach ($note->payments as $payment) {
            Payment::query()->create([
                'branch_id' => $invoice->branch_id,
                'payable_type' => Invoice::class,
                'payable_id' => $invoice->id,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'payment_reference' => $payment->payment_reference,
                'paid_on' => $payment->paid_on,
                'note' => 'Paid on delivery note '.$note->delivery_note_number,
            ]);
            $copied += (float) $payment->amount;
            $method = $payment->payment_method;
            $reference = $payment->payment_reference;
        }

        if ($copied > 0) {
            $invoice->unsetRelation('payments');
            $invoice->update([
                'payment_method' => $method ?? null,
                'payment_reference' => $reference ?? null,
                'payment_status' => $invoice->balanceDue() <= 0.005 ? PaymentStatus::PAID : PaymentStatus::PENDING,
            ]);
        }

        return round($copied, 2);
    }

    /**
     * Only a live document takes payment: not a voided invoice or a cancelled note.
     */
    private function assertPayable(Invoice|DeliveryNote $payable): void
    {
        if ($payable instanceof Invoice && $payable->status !== InvoiceStatus::COMPLETED) {
            throw ValidationException::withMessages(['payment' => __('A voided invoice cannot take payment.')]);
        }

        if ($payable instanceof DeliveryNote && $payable->status !== DeliveryNoteStatus::ISSUED) {
            throw ValidationException::withMessages(['payment' => __('Only an issued delivery note can take payment.')]);
        }
    }
}
