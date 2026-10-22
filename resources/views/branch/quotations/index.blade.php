{{-- Quotation list. New documents open POS. --}}
@extends('layouts.branch')

@section('title', __('Quotations'))
@section('page-title', __('Quotations'))

@section('content')
<x-page-header :title="__('Quotations')" :subtitle="__('Priced documents for this branch.')">
    <x-slot:actions>
        @can('create', \App\Models\Quotation::class)
            <x-btn href="{{ route('branch.pos.show', ['kind' => 'quotation']) }}">{{ __('New Quotation') }}</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" :placeholder="__('Search number or customer…')" :aria-label="__('Search quotations')" />
    <select name="status" class="input-field" aria-label="{{ __('Filter by status') }}">
        <option value="">{{ __('All statuses') }}</option>
        @foreach(\App\Enums\QuotationStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('branch.quotations.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($quotations->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No quotations yet')" :message="__('Open POS to price items for a customer.')" icon="request_quote">
                <x-slot:actions>
                    @can('create', \App\Models\Quotation::class)
                        <x-btn href="{{ route('branch.pos.show', ['kind' => 'quotation']) }}">{{ __('New Quotation') }}</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Quotation') }}</th>
                        <th>{{ __('Customer') }}</th>
                        <th class="table-admin__num">{{ __('Lines') }}</th>
                        <th class="table-admin__num">{{ __('Total') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Created by') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
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
                                    @if(auth()->user()->hasPermission('quotations.delete'))
                                        <x-delete-record type="quotations" :record="$quotation" :icon="true" />
                                    @endif
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
