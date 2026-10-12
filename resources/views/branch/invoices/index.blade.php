{{-- Invoice list. New invoices open POS. --}}
@extends('layouts.branch')

@section('title', 'Invoices')
@section('page-title', 'Invoices')

@section('content')
<x-page-header title="Invoices" subtitle="Finalised sales. Corrections are made by voiding, never editing.">
    <x-slot:actions>
        @can('create', \App\Models\Quotation::class)
            <x-btn href="{{ route('branch.pos.show') }}">New Invoice</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" placeholder="Search number or customer…" aria-label="Search invoices" />
    <select name="status" class="input-field" aria-label="Filter by status">
        <option value="">All statuses</option>
        @foreach(\App\Enums\InvoiceStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">Filter</x-btn>
        <a href="{{ route('branch.invoices.index') }}" class="btn btn--secondary">Reset</a>
    </div>
</form>

<div class="panel">
    @if($invoices->isEmpty())
        <div class="panel__body">
            <x-empty-state
                title="No invoices yet"
                message="Open POS to take payment and print the first invoice."
                icon="receipt_long"
            >
                <x-slot:actions>
                    @can('create', \App\Models\Quotation::class)
                        <x-btn href="{{ route('branch.pos.show') }}">New Invoice</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th class="table-admin__num">Lines</th>
                        <th class="table-admin__num">Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th class="table-admin__actions-col">Actions</th>
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
                            <td>{{ $invoice->payment_method->label() }}</td>
                            <td><x-status-badge :status="$invoice->status" /></td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('branch.invoices.show', $invoice)" />
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
