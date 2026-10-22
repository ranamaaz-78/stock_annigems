{{--
    Quotation detail — renders the same document sheet the customer receives,
    with edit / convert / print actions in the page header.
--}}
@extends('layouts.branch')

@section('title', $quotation->quotation_number)
@section('page-title', __('Quotation Detail'))

@section('content')
<x-page-header :title="$quotation->quotation_number" :subtitle="setting_date($quotation->quotation_date)">
    <x-slot:actions>
        @if($quotation->isEditable())
            @can('update', $quotation)
                <x-btn href="{{ route('branch.quotations.edit', $quotation) }}" variant="secondary">{{ __('Edit') }}</x-btn>
            @endcan
            @can('convert', $quotation)
                <x-btn type="button" data-modal-open="convert-modal">{{ __('Convert to invoice') }}</x-btn>
            @endcan
        @endif
        <x-btn type="button" variant="secondary" onclick="window.print()">{{ __('Print') }}</x-btn>
        @if(auth()->user()->hasPermission('quotations.delete'))
            <x-delete-record type="quotations" :record="$quotation" />
        @endif
        <x-btn href="{{ route('branch.quotations.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

@include('branch.quotations._sheet', ['quotation' => $quotation])

<div class="doc-meta">
    <x-attribution :record="$quotation" />
    @if($quotation->convertedInvoice)
        <p class="doc-meta__link">
            <a href="{{ route('branch.invoices.show', $quotation->convertedInvoice) }}">
                {{ __('Open invoice :number', ['number' => $quotation->convertedInvoice->invoice_number]) }}
            </a>
        </p>
    @endif
</div>

@can('convert', $quotation)
    <x-modal id="convert-modal" :title="__('Convert to invoice')">
        <p class="detail-list__value">
            {{ __('This locks the quotation and marks quoted jewellery as sold. Loose stone quantity was already cut when the quotation was saved.') }}
        </p>
        <form method="POST" action="{{ route('branch.quotations.convert', $quotation) }}" data-loading-form>
            @csrf
            <div class="form-grid mt-3">
                <div>
                    <label class="form-label" for="quote_payment_status">{{ __('Payment') }}</label>
                    <select id="quote_payment_status" name="payment_status" class="input-field" data-no-select2 data-quote-status>
                        <option value="pending" selected>{{ __('Pending') }}</option>
                        <option value="paid">{{ __('Paid now') }}</option>
                    </select>
                </div>
            </div>
            <div class="form-grid mt-3 hidden" data-quote-method>
                <x-form.select name="payment_method" :label="__('Payment method')" :options="\App\Enums\PaymentMethod::options()" />
                <x-form.input name="payment_reference" :label="__('Reference')" maxlength="100" />
            </div>
            <div class="form-actions">
                <x-btn type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-btn>
                <x-btn type="submit" :loading-text="__('Converting…')">{{ __('Create invoice') }}</x-btn>
            </div>
        </form>
    </x-modal>

    @push('scripts')
    <script>
        // The method is asked only when the invoice is paid now; a pending invoice records no payment.
        (() => {
            const status = document.querySelector('[data-quote-status]');
            const box = document.querySelector('[data-quote-method]');
            if (!status || !box) return;
            const sync = () => {
                const paid = status.value === 'paid';
                box.classList.toggle('hidden', !paid);
                const select = box.querySelector('select');
                if (select) select.required = paid;
            };
            status.addEventListener('change', sync);
            sync();
        })();
    </script>
    @endpush
@endcan
@endsection
