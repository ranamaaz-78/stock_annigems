{{-- Customer detail: profile plus cross-branch quotation / invoice history. --}}
@extends('layouts.branch')

@section('title', $customer->name)
@section('page-title', __('Customer Detail'))


@section('content')
<x-page-header :title="$customer->name" :subtitle="$customer->customer_code">
    <x-slot:actions>
        @can('update', $customer)
            <x-btn href="{{ route('branch.customers.edit', $customer) }}" variant="secondary">{{ __('Edit') }}</x-btn>
        @endcan
        <x-btn href="{{ route('branch.customers.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel-stack">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter items-start">
        <div class="panel lg:col-span-2">
            <div class="panel__header">
                <h2 class="panel__title">{{ __('Profile') }}</h2>
                <x-status-badge :status="$customer->status" />
            </div>
            <div class="panel__body">
                <dl class="detail-list">
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Phone') }}</dt>
                        <dd class="detail-list__value font-data-mono">{{ $customer->phone }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Alternate phone') }}</dt>
                        <dd class="detail-list__value font-data-mono">{{ $customer->alternate_phone ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Email') }}</dt>
                        <dd class="detail-list__value">{{ $customer->email ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('City') }}</dt>
                        <dd class="detail-list__value">{{ $customer->city ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Registered') }}</dt>
                        <dd class="detail-list__value">{{ setting_date($customer->created_at) }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('NIE / NIF') }}</dt>
                        <dd class="detail-list__value">{{ $customer->nie ?: '—' }}</dd>
                    </div>
                    <div class="detail-list__item detail-list__item--full">
                        <dt class="detail-list__term">{{ __('Address') }}</dt>
                        <dd class="detail-list__value">{{ $customer->address ?? '—' }}</dd>
                    </div>
                    @if($customer->notes)
                        <div class="detail-list__item detail-list__item--full">
                            <dt class="detail-list__term">{{ __('Internal notes') }}</dt>
                            <dd class="detail-list__value">{{ $customer->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>

        <div class="panel">
            <div class="panel__header">
                <h2 class="panel__title">{{ __('Record') }}</h2>
            </div>
            <div class="panel__body">
                <x-attribution :record="$customer" />

                @can('deactivate', $customer)
                    @if($customer->status)
                        <form
                            method="POST"
                            action="{{ route('branch.customers.deactivate', $customer) }}"
                            id="deactivate-customer-form"
                            class="form-actions"
                        >
                            @csrf
                            <input type="hidden" name="confirm" value="1">
                            <x-btn type="button" variant="danger" id="deactivate-customer-btn">{{ __('Deactivate') }}</x-btn>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">{{ __('Quotation history') }}</h2>
            <p class="panel__subtitle">{{ __('All branches') }}</p>
        </div>
        @if($quotations->isEmpty())
            <div class="panel__body">
                <x-empty-state :title="__('No quotations yet')" :message="__('Quotations raised for this customer will appear here.')" icon="request_quote" />
            </div>
        @else
            <div class="panel__scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th>{{ __('Number') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Branch') }}</th>
                            <th>{{ __('Total') }}</th>
                            <th>{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($quotations as $quotation)
                            <tr>
                                <td class="font-data-mono">
                                    <a href="{{ route('branch.quotations.show', $quotation) }}" class="form-link">{{ $quotation->quotation_number }}</a>
                                </td>
                                <td>{{ setting_date($quotation->quotation_date) }}</td>
                                <td>{{ $quotation->branch?->name ?? '—' }}</td>
                                <td class="font-data-mono">{{ setting_money($quotation->grand_total) }}</td>
                                <td><x-status-badge :status="$quotation->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">{{ __('Invoice history') }}</h2>
            <p class="panel__subtitle">{{ __('All branches') }}</p>
        </div>
        @if($invoices->isEmpty())
            <div class="panel__body">
                <x-empty-state :title="__('No invoices yet')" :message="__('Completed sales for this customer will appear here.')" icon="receipt_long" />
            </div>
        @else
            <div class="panel__scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th>{{ __('Number') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Branch') }}</th>
                            <th>{{ __('Total') }}</th>
                            <th>{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoices as $invoice)
                            <tr>
                                <td class="font-data-mono">
                                    <a href="{{ route('branch.invoices.show', $invoice) }}" class="form-link">{{ $invoice->invoice_number }}</a>
                                </td>
                                <td>{{ setting_date($invoice->invoice_date) }}</td>
                                <td>{{ $invoice->branch?->name ?? '—' }}</td>
                                <td class="font-data-mono">{{ setting_money($invoice->grand_total) }}</td>
                                <td><x-status-badge :status="$invoice->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
    /** Confirm before deactivating — the customer disappears from active search. */
    document.getElementById('deactivate-customer-btn')?.addEventListener('click', async () => {
        const ok = await window.AnnigemsAlert.confirmAction({
            title: @js(__('Deactivate customer?')),
            text: @js(__('They will no longer appear in active customer searches. Their history is kept.')),
            confirmButtonText: 'Deactivate',
        });
        if (ok) {
            document.getElementById('deactivate-customer-form').submit();
        }
    });
</script>
@endpush
@endsection
