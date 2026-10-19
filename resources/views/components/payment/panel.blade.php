{{--
    Payment panel for an invoice or delivery note detail page: the status, what
    was paid and what is still due, the payments recorded, and the button that
    records one. Recording a payment locks the document against edits.
--}}
@props(['document', 'action', 'who' => 'document'])

@php
    $payments = $document->payments;
    $paid = $document->paidAmount();
    $due = $document->balanceDue();
    $canRecord = auth()->user()->can('recordPayment', $document);
    $number = $document->invoice_number ?? $document->delivery_note_number;
@endphp

<div class="panel no-print" style="max-width: 56rem; margin: var(--gutter) auto 0">
    <div class="panel__header">
        <h2 class="panel__title">Payment</h2>
        <x-status-badge :status="$document->payment_status" />
    </div>
    <div class="panel__body">
        <dl class="detail-list" style="margin-bottom: 0.75rem">
            <div class="detail-list__row"><dt class="detail-list__label">Total</dt><dd class="detail-list__value">{{ setting_money($document->grand_total) }}</dd></div>
            <div class="detail-list__row"><dt class="detail-list__label">Paid</dt><dd class="detail-list__value">{{ setting_money($paid) }}</dd></div>
            <div class="detail-list__row"><dt class="detail-list__label">Balance due</dt><dd class="detail-list__value"><strong>{{ setting_money($due) }}</strong></dd></div>
        </dl>

        @if($payments->isEmpty())
            <p class="text-body-sm">No payment recorded yet.@if($document->canBeEdited()) The {{ $who }} can still be edited; recording a payment locks it.@endif</p>
        @else
            <div class="table-scroll">
                <table class="table-admin table-admin--dense">
                    <thead>
                        <tr><th>Date</th><th>Method</th><th>Reference</th><th>Recorded by</th><th class="table-admin__num">Amount</th></tr>
                    </thead>
                    <tbody>
                        @foreach($payments as $payment)
                            <tr>
                                <td>{{ setting_date($payment->paid_on) }}</td>
                                <td>{{ $payment->payment_method->label() }}@if($payment->note)<span class="table-meta">{{ $payment->note }}</span>@endif</td>
                                <td>{{ $payment->payment_reference ?: '—' }}</td>
                                <td>{{ $payment->creator?->name ?? '—' }}</td>
                                <td class="table-admin__num table-admin__num--strong">{{ setting_money($payment->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($canRecord)
            <div class="form-actions" style="margin-top: 0.75rem">
                <x-btn type="button" data-modal-open="record-payment-modal">Record payment</x-btn>
            </div>
        @endif
    </div>
</div>

@if($canRecord)
    <x-modal id="record-payment-modal" title="Record payment">
        <p class="detail-list__value">
            {{ $number }} — {{ setting_money($due) }} is due. Once the payment is recorded this {{ $who }} can no longer be edited.
        </p>
        <form method="POST" action="{{ $action }}" data-loading-form>
            @csrf
            <div class="form-grid mt-3">
                <x-form.select name="payment_method" label="Payment method" :options="\App\Enums\PaymentMethod::options()" required />
                <x-form.input name="payment_reference" label="Reference" maxlength="100" />
            </div>
            <div class="form-actions">
                <x-btn type="button" variant="secondary" data-modal-close>Cancel</x-btn>
                <x-btn type="submit" loading-text="Saving…">Record {{ setting_money($due) }}</x-btn>
            </div>
        </form>
    </x-modal>
@endif
