{{--
    Quotation detail — renders the same document sheet the customer receives,
    with edit / convert / print actions in the page header.
--}}
@extends('layouts.branch')

@section('title', $quotation->quotation_number)
@section('page-title', 'Quotation Detail')

@section('content')
<x-page-header :title="$quotation->quotation_number" :subtitle="setting_date($quotation->quotation_date)">
    <x-slot:actions>
        @if($quotation->isEditable())
            @can('update', $quotation)
                <x-btn href="{{ route('branch.quotations.edit', $quotation) }}" variant="secondary">Edit</x-btn>
            @endcan
            @can('convert', $quotation)
                <x-btn type="button" data-modal-open="convert-modal">Convert to invoice</x-btn>
            @endcan
        @endif
        <x-btn href="{{ route('branch.quotations.print', $quotation) }}" variant="secondary" target="_blank" rel="noopener">Print</x-btn>
        <x-btn href="{{ route('branch.quotations.index') }}" variant="secondary">Back</x-btn>
    </x-slot:actions>
</x-page-header>

@include('branch.quotations._sheet', ['quotation' => $quotation])

<div class="doc-meta">
    <x-attribution :record="$quotation" />
    @if($quotation->convertedInvoice)
        <p class="doc-meta__link">
            <a href="{{ route('branch.invoices.show', $quotation->convertedInvoice) }}">
                Open invoice {{ $quotation->convertedInvoice->invoice_number }}
            </a>
        </p>
    @endif
</div>

@can('convert', $quotation)
    <x-modal id="convert-modal" title="Convert to invoice">
        <p class="detail-list__value">
            This locks the quotation and marks quoted jewellery as sold. Loose stone quantity was already cut when the quotation was saved.
        </p>
        <form method="POST" action="{{ route('branch.quotations.convert', $quotation) }}" data-loading-form>
            @csrf
            <div class="form-grid mt-3">
                <x-form.select name="payment_method" label="Payment method" :options="\App\Enums\PaymentMethod::options()" required />
                <x-form.input name="payment_reference" label="Reference (optional)" maxlength="100" />
            </div>
            <div class="form-actions">
                <x-btn type="button" variant="secondary" data-modal-close>Cancel</x-btn>
                <x-btn type="submit" loading-text="Converting…">Create invoice</x-btn>
            </div>
        </form>
    </x-modal>
@endcan
@endsection
