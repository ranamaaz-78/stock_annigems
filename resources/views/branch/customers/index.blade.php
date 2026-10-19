{{-- Customer registry list. Design: customer_management. --}}
@extends('layouts.branch')

@section('title', __('Customers'))
@section('page-title', __('Customers'))

@section('content')
<x-page-header :title="__('Customer Registry')" :subtitle="__('Global customer master data — shared across all branches.')">
    <x-slot:actions>
        @can('create', \App\Models\Customer::class)
            <x-btn href="{{ route('branch.customers.create') }}">{{ __('Add Customer') }}</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" :placeholder="__('Search name, phone, code…')" :aria-label="__('Search customers')" />
    <select name="status" class="input-field" aria-label="{{ __('Filter by status') }}">
        <option value="">{{ __('All statuses') }}</option>
        <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('Active') }}</option>
        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('Inactive') }}</option>
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('branch.customers.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($customers->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No customers found')" :message="__('Register a customer or adjust your search.')" icon="group">
                <x-slot:actions>
                    @can('create', \App\Models\Customer::class)
                        <x-btn href="{{ route('branch.customers.create') }}">{{ __('Add Customer') }}</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Client') }}</th>
                        <th>{{ __('Contact') }}</th>
                        <th>{{ __('City') }}</th>
                        <th class="table-admin__num">{{ __('Quotations') }}</th>
                        <th class="table-admin__num">{{ __('Invoices') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($customers as $customer)
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('branch.customers.show', $customer)"
                                    :title="$customer->name"
                                    :meta="$customer->customer_code"
                                    :mark="true"
                                />
                            </td>
                            <td class="font-data-mono">{{ $customer->phone }}</td>
                            <td>{{ $customer->city ?? '—' }}</td>
                            <td class="table-admin__num">{{ $customer->quotations_count }}</td>
                            <td class="table-admin__num">{{ $customer->invoices_count }}</td>
                            <td><x-status-badge :status="$customer->status" /></td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('branch.customers.show', $customer)" />
                                </x-table.actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($customers->total() > 0)
            <div class="panel__footer">
                {{ $customers->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
