{{--
    Delivery note detail — the same sheet the client receives, with cancel,
    convert and print actions in the page header.
--}}
@extends('layouts.branch')

@section('title', $note->delivery_note_number)
@section('page-title', __('Delivery Note Detail'))

@section('content')
<x-page-header :title="$note->delivery_note_number" :subtitle="setting_date($note->delivery_date)">
    <x-slot:actions>
        @can('update', $note)
            <x-btn href="{{ route('branch.pos.show', ['delivery_note' => $note->uuid]) }}" variant="secondary">{{ __('Edit') }}</x-btn>
        @endcan
        @can('convert', $note)
            <x-btn type="button" data-modal-open="convert-modal">{{ __('Convert to invoice') }}</x-btn>
        @endcan
        @can('cancel', $note)
            <x-btn type="button" variant="danger" id="cancel-note-btn">{{ __('Cancel') }}</x-btn>
        @endcan
        @can('recordPayment', $note)
            <x-btn type="button" data-modal-open="record-payment-modal">{{ __('Record payment') }}</x-btn>
        @endcan
        <x-btn type="button" variant="secondary" onclick="window.print()">{{ __('Print') }}</x-btn>
        <x-btn href="{{ route('branch.delivery-notes.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

@include('branch.delivery-notes._sheet', ['note' => $note])

<x-payment.panel :document="$note" :action="route('branch.delivery-notes.payment', $note)" who="delivery note" />

<div class="doc-meta">
    <x-attribution :record="$note" />
    @if($note->invoice)
        <p class="doc-meta__link">
            <a href="{{ route('branch.invoices.show', $note->invoice) }}">
                {{ __('Open invoice :number', ['number' => $note->invoice->invoice_number]) }}
            </a>
        </p>
    @endif
</div>

@can('convert', $note)
    @php
        $convertBase = round((float) $note->subtotal - (float) $note->discount_amount, 2);
        $convertPaid = $note->paidAmount();
    @endphp
    <x-modal id="convert-modal" :title="__('Convert to invoice')">
        <p class="detail-list__value">
            {{ __('This creates an invoice with the same lines and discount. Choose the IVA (and recargo, if it applies) for it.') }}
            @if($convertPaid > 0)
                {{ __('What was already paid on this note (:amount) counts toward the invoice; only the difference is left to pay.', ['amount' => setting_money($convertPaid)]) }}
            @endif
            {{ __('Stock is not cut again: it already left when the delivery note was issued.') }}
        </p>
        <form method="POST" action="{{ route('branch.delivery-notes.convert', $note) }}" data-loading-form
              data-convert-form data-base="{{ $convertBase }}" data-paid="{{ $convertPaid }}" data-currency="{{ setting('currency_symbol', 'Rs') }}">
            @csrf
            @if($taxEnabled)
                <div class="form-grid mt-3">
                    <div>
                        <label class="form-label" for="convert_tax_rate_uuid">{{ __('IVA') }}</label>
                        <select id="convert_tax_rate_uuid" name="tax_rate_uuid" class="input-field" data-no-select2 data-convert-iva>
                            <option value="" data-rate="0">{{ __('No IVA') }}</option>
                            @foreach($ivaRates as $rate)
                                <option value="{{ $rate['uuid'] }}" data-rate="{{ $rate['rate'] }}" @selected($rate['uuid'] === old('tax_rate_uuid', $defaultIvaUuid))>{{ $rate['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if(count($recargoRates))
                        <div>
                            <label class="form-label" for="convert_recargo_rate_uuid">{{ __('Recargo de equivalencia') }}</label>
                            <select id="convert_recargo_rate_uuid" name="recargo_rate_uuid" class="input-field" data-no-select2 data-convert-recargo>
                                <option value="" data-rate="0">{{ __('None') }}</option>
                                @foreach($recargoRates as $rate)
                                    <option value="{{ $rate['uuid'] }}" data-rate="{{ $rate['rate'] }}" @selected($rate['uuid'] === old('recargo_rate_uuid'))>{{ $rate['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
            @endif

            <dl class="detail-list mt-3">
                <div class="detail-list__row"><dt class="detail-list__label">{{ __('Invoice total') }}</dt><dd class="detail-list__value" data-convert-total>—</dd></div>
                @if($convertPaid > 0)
                    <div class="detail-list__row"><dt class="detail-list__label">{{ __('Already paid on the note') }}</dt><dd class="detail-list__value">−{{ setting_money($convertPaid) }}</dd></div>
                @endif
                <div class="detail-list__row"><dt class="detail-list__label"><strong>{{ __('To pay now') }}</strong></dt><dd class="detail-list__value"><strong data-convert-due>—</strong></dd></div>
            </dl>

            <div data-convert-payment>
                <div class="form-grid mt-3">
                    <div>
                        <label class="form-label" for="convert_payment_status">{{ __('Payment') }}</label>
                        <select id="convert_payment_status" name="payment_status" class="input-field" data-no-select2 data-convert-status>
                            <option value="paid" @selected($convertPaid > 0)>{{ $convertPaid > 0 ? __('Pay the difference now') : __('Paid now') }}</option>
                            <option value="pending" @selected($convertPaid <= 0)>{{ __('Pending') }}</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid mt-3" data-convert-method>
                    <x-form.select name="payment_method" :label="__('Payment method')" :options="$paymentMethods" />
                    <x-form.input name="payment_reference" :label="__('Reference')" maxlength="100" />
                </div>
            </div>
            <div class="form-actions">
                <x-btn type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-btn>
                <x-btn type="submit" :loading-text="__('Converting…')">{{ __('Create invoice') }}</x-btn>
            </div>
        </form>
    </x-modal>

    @push('scripts')
    <script>
        /**
         * Shows what the invoice will total with the chosen IVA and recargo, and
         * how much of it is still to pay after what the note already took. The
         * server works the same figures out again.
         */
        (() => {
            const form = document.querySelector('[data-convert-form]');
            if (!form) return;

            const base = parseFloat(form.dataset.base) || 0;
            const paid = parseFloat(form.dataset.paid) || 0;
            const money = (n) => `${form.dataset.currency} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            const round = (n) => Math.round(n * 100) / 100;
            const rate = (select) => parseFloat(select?.selectedOptions[0]?.dataset.rate || '0') || 0;
            const iva = form.querySelector('[data-convert-iva]');
            const recargo = form.querySelector('[data-convert-recargo]');
            const status = form.querySelector('[data-convert-status]');
            const method = form.querySelector('[data-convert-method]');
            const paymentBox = form.querySelector('[data-convert-payment]');

            const update = () => {
                const total = round(base + round(base * rate(iva) / 100) + round(base * rate(recargo) / 100));
                const due = Math.max(0, round(total - paid));
                form.querySelector('[data-convert-total]').textContent = money(total);
                form.querySelector('[data-convert-due]').textContent = money(due);
                // Nothing left to pay: no payment fields at all.
                paymentBox.classList.toggle('hidden', due <= 0.005);
                method.classList.toggle('hidden', status.value !== 'paid');
                const select = method.querySelector('select');
                if (select) select.required = due > 0.005 && status.value === 'paid';
            };

            [iva, recargo, status].forEach((el) => el?.addEventListener('change', update));
            update();
        })();
    </script>
    @endpush
@endcan

@can('cancel', $note)
    <form method="POST" action="{{ route('branch.delivery-notes.cancel', $note) }}" id="cancel-note-form" class="hidden">
        @csrf
        <input type="hidden" name="cancel_reason" id="cancel_reason_input">
    </form>

    @push('scripts')
    <script>
        /**
         * Cancelling returns every item to stock, so it always needs a typed
         * reason. The reason is validated again on the server.
         */
        document.getElementById('cancel-note-btn')?.addEventListener('click', async () => {
            const reason = await window.AnnigemsAlert.promptText({
                title: @js(__('Cancel this delivery note?')),
                text: @js(__('The items return to stock.')),
                inputLabel: @js(__('Reason (at least 10 characters)')),
                minLength: 10,
                confirmButtonText: @js(__('Cancel delivery note')),
            });
            if (!reason) return;
            document.getElementById('cancel_reason_input').value = reason;
            document.getElementById('cancel-note-form').submit();
        });
    </script>
    @endpush
@endcan
@endsection