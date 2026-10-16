{{--
    Delivery note detail — the same sheet the client receives, with cancel,
    convert and print actions in the page header.
--}}
@extends('layouts.branch')

@section('title', $note->delivery_note_number)
@section('page-title', 'Delivery Note Detail')

@section('content')
<x-page-header :title="$note->delivery_note_number" :subtitle="setting_date($note->delivery_date)">
    <x-slot:actions>
        @can('convert', $note)
            <x-btn type="button" data-modal-open="convert-modal">Convert to invoice</x-btn>
        @endcan
        @can('cancel', $note)
            <x-btn type="button" variant="danger" id="cancel-note-btn">Cancel</x-btn>
        @endcan
        <x-btn href="{{ route('branch.delivery-notes.print', $note) }}" variant="secondary" target="_blank" rel="noopener">Print</x-btn>
        <x-btn href="{{ route('branch.delivery-notes.index') }}" variant="secondary">Back</x-btn>
    </x-slot:actions>
</x-page-header>

@include('branch.delivery-notes._sheet', ['note' => $note])

<div class="doc-meta">
    <x-attribution :record="$note" />
    @if($note->invoice)
        <p class="doc-meta__link">
            <a href="{{ route('branch.invoices.show', $note->invoice) }}">
                Open invoice {{ $note->invoice->invoice_number }}
            </a>
        </p>
    @endif
</div>

@can('convert', $note)
    <x-modal id="convert-modal" title="Convert to invoice">
        <p class="detail-list__value">
            This creates an invoice with the same lines and discount and takes payment. Choose the IVA (and recargo, if it applies) for this invoice. Stock is not cut again: it already left when the delivery note was issued.
        </p>
        <form method="POST" action="{{ route('branch.delivery-notes.convert', $note) }}" data-loading-form>
            @csrf
            @if($taxEnabled)
                <div class="form-grid mt-3">
                    <div>
                        <label class="form-label" for="convert_tax_rate_uuid">IVA</label>
                        <select id="convert_tax_rate_uuid" name="tax_rate_uuid" class="input-field" data-no-select2>
                            <option value="">No IVA</option>
                            @foreach($ivaRates as $rate)
                                <option value="{{ $rate['uuid'] }}" @selected($rate['uuid'] === old('tax_rate_uuid', $defaultIvaUuid))>{{ $rate['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if(count($recargoRates))
                        <div>
                            <label class="form-label" for="convert_recargo_rate_uuid">Recargo de equivalencia</label>
                            <select id="convert_recargo_rate_uuid" name="recargo_rate_uuid" class="input-field" data-no-select2>
                                <option value="">None</option>
                                @foreach($recargoRates as $rate)
                                    <option value="{{ $rate['uuid'] }}" @selected($rate['uuid'] === old('recargo_rate_uuid'))>{{ $rate['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
            @endif
            <div class="form-grid mt-3">
                <x-form.select name="payment_method" label="Payment method" :options="$paymentMethods" required />
                <x-form.input name="payment_reference" label="Reference (optional)" maxlength="100" />
            </div>
            <div class="form-actions">
                <x-btn type="button" variant="secondary" data-modal-close>Cancel</x-btn>
                <x-btn type="submit" loading-text="Converting…">Create invoice</x-btn>
            </div>
        </form>
    </x-modal>
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
                title: 'Cancel this delivery note?',
                text: 'The items return to stock.',
                inputLabel: 'Reason (at least 10 characters)',
                minLength: 10,
                confirmButtonText: 'Cancel delivery note',
            });
            if (!reason) return;
            document.getElementById('cancel_reason_input').value = reason;
            document.getElementById('cancel-note-form').submit();
        });
    </script>
    @endpush
@endcan
@endsection