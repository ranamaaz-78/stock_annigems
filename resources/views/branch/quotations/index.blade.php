{{-- Quotation list. New documents open POS. --}}
@extends('layouts.branch')

@section('title', 'Quotations')
@section('page-title', 'Quotations')

@section('content')
<x-page-header title="Quotations" subtitle="Priced documents for this branch.">
    <x-slot:actions>
        @can('create', \App\Models\Quotation::class)
            <x-btn href="{{ route('branch.pos.show') }}">New Quotation</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" placeholder="Search number or customer…" aria-label="Search quotations" />
    <select name="status" class="input-field" aria-label="Filter by status">
        <option value="">All statuses</option>
        @foreach(\App\Enums\QuotationStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">Filter</x-btn>
        <a href="{{ route('branch.quotations.index') }}" class="btn btn--secondary">Reset</a>
    </div>
</form>

<div class="panel">
    @if($quotations->isEmpty())
        <div class="panel__body">
            <x-empty-state title="No quotations yet" message="Open POS to price items for a customer." icon="request_quote">
                <x-slot:actions>
                    @can('create', \App\Models\Quotation::class)
                        <x-btn href="{{ route('branch.pos.show') }}">New Quotation</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>Quotation</th>
                        <th>Customer</th>
                        <th class="table-admin__num">Lines</th>
                        <th class="table-admin__num">Total</th>
                        <th>Status</th>
                        <th>Created by</th>
                        <th class="table-admin__actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($quotations as $quotation)
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('branch.quotations.show', $quotation)"
                                    :title="$quotation->quotation_number"
                                    :meta="setting_date($quotation->quotation_date)"
                                    icon="request_quote"
                                />
                            </td>
                            <td>{{ $quotation->displayCustomerName() }}</td>
                            <td class="table-admin__num">{{ $quotation->items_count }}</td>
                            <td class="table-admin__num table-admin__num--strong">{{ setting_money($quotation->grand_total) }}</td>
                            <td><x-status-badge :status="$quotation->status" /></td>
                            <td>
                                <x-audit-actor
                                    :user="$quotation->creator"
                                    :role="$quotation->created_by_role"
                                    :at="$quotation->created_at"
                                />
                            </td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('branch.quotations.show', $quotation)" />
                                </x-table.actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($quotations->total() > 0)
            <div class="panel__footer">
                {{ $quotations->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
