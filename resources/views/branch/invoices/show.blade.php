{{--
    Invoice detail — renders the same document sheet the customer receives
    (design/invoice_view), with branch actions in the page header.
--}}
@extends('layouts.branch')

@section('title', $invoice->invoice_number)
@section('page-title', __('Invoice Detail'))

@section('content')
<x-page-header :title="$invoice->invoice_number" :subtitle="setting_date($invoice->invoice_date)">
    <x-slot:actions>
        @can('update', $invoice)
            <x-btn href="{{ route('branch.pos.show', ['invoice' => $invoice->uuid]) }}">{{ __('Edit') }}</x-btn>
        @endcan
        @can('recordPayment', $invoice)
            <x-btn type="button" data-modal-open="record-payment-modal">{{ __('Record payment') }}</x-btn>
        @endcan
        <x-btn type="button" variant="secondary" onclick="window.print()">{{ __('Print') }}</x-btn>
        @can('void', $invoice)
            <x-btn type="button" variant="danger" id="void-invoice-btn">{{ __('Void') }}</x-btn>
        @endcan
        @if(auth()->user()->hasPermission('invoices.delete'))
            <x-delete-record type="invoices" :record="$invoice" text="{{ __('Items return to stock and the invoice moves to Trash.') }}" />
        @endif
        <x-btn href="{{ route('branch.invoices.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

@include('branch.invoices._sheet', ['invoice' => $invoice])

<x-payment.panel :document="$invoice" :action="route('branch.invoices.payment', $invoice)" who="invoice" />

<div class="doc-meta">
    <x-attribution :record="$invoice" />
    @if($invoice->deliveryNote)
        <p class="doc-meta__link">
            {{ __('From delivery note') }}
            <a href="{{ route('branch.delivery-notes.show', $invoice->deliveryNote) }}">{{ $invoice->deliveryNote->delivery_note_number }}</a>
        </p>
    @endif
    <p class="doc-meta__link">
        {{ __('Public verification:') }} <a href="{{ $verifyUrl }}" target="_blank" rel="noopener">{{ $verifyUrl }}</a>
    </p>
</div>

@can('void', $invoice)
    <form method="POST" action="{{ route('branch.invoices.void', $invoice) }}" id="void-invoice-form" class="hidden">
        @csrf
        <input type="hidden" name="void_reason" id="void_reason_input">
    </form>

    @push('scripts')
    <script>
        /**
         * Voiding restores stock and invalidates the public QR, so it always
         * requires a typed reason. The reason is re-validated server-side.
         */
        document.getElementById('void-invoice-btn')?.addEventListener('click', async () => {
            const reason = await window.AnnigemsAlert.promptText({
                title: @js(__('Void this invoice?')),
                text: @js(__('Items return to stock and the public verification page will show it as void.')),
                inputLabel: @js(__('Reason (at least 10 characters)')),
                minLength: 10,
                confirmButtonText: @js(__('Void invoice')),
            });
            if (!reason) return;
            document.getElementById('void_reason_input').value = reason;
            document.getElementById('void-invoice-form').submit();
        });
    </script>
    @endpush
@endcan
@endsection
