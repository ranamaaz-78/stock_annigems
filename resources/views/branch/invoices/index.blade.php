{{-- Invoice list. New invoices open POS. --}}
@extends('layouts.branch')

@section('title', __('Invoices'))
@section('page-title', __('Invoices'))

@section('content')
<x-page-header :title="__('Invoices')" :subtitle="__('Finalised sales. Corrections are made by voiding, never editing.')">
    <x-slot:actions>
        @can('create', \App\Models\Quotation::class)
            <x-btn href="{{ route('branch.pos.show', ['kind' => 'invoice']) }}">{{ __('New Invoice') }}</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" :placeholder="__('Search number or customer…')" :aria-label="__('Search invoices')" />
    <select name="status" class="input-field" aria-label="{{ __('Filter by status') }}">
        <option value="">{{ __('All statuses') }}</option>
        @foreach(\App\Enums\InvoiceStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('branch.invoices.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($invoices->isEmpty())
        <div class="panel__body">
            <x-empty-state
                :title="__('No invoices yet')"
                :message="__('Open POS to take payment and print the first invoice.')"
                icon="receipt_long"
            >
                <x-slot:actions>
                    @can('create', \App\Models\Quotation::class)
                        <x-btn href="{{ route('branch.pos.show', ['kind' => 'invoice']) }}">{{ __('New Invoice') }}</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Invoice') }}</th>
                        <th>{{ __('Customer') }}</th>
                        <th class="table-admin__num">{{ __('Lines') }}</th>
                        <th class="table-admin__num">{{ __('Total') }}</th>
                        <th>{{ __('Payment') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('branch.invoices.show', $invoice)"
                                    :title="$invoice->invoice_number"
                                    :meta="setting_date($invoice->invoice_date)"
                                    icon="receipt_long"
                                />
                            </td>
                            <td>{{ $invoice->customer_name_snapshot }}</td>
                            <td class="table-admin__num">{{ $invoice->items_count }}</td>
                            <td class="table-admin__num table-admin__num--strong">{{ setting_money($invoice->grand_total) }}</td>
                            <td><x-status-badge :status="$invoice->payment_status" />@if($invoice->payment_method)<span class="table-meta">{{ $invoice->payment_method->label() }}</span>@endif</td>
                            <td><x-status-badge :status="$invoice->status" /></td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('branch.invoices.show', $invoice)" />
                                    @if(auth()->user()->hasPermission('invoices.delete'))
                                        <x-delete-record type="invoices" :record="$invoice" :icon="true" text="{{ __('Items return to stock and the invoice moves to Trash.') }}" />
                                    @endif
                                </x-table.actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($invoices->total() > 0)
            <div class="panel__footer">
                {{ $invoices->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
