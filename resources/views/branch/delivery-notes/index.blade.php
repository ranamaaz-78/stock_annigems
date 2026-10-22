{{-- Delivery note (albarán) list. New notes are made from the POS. --}}
@extends('layouts.branch')

@section('title', __('Delivery Notes'))
@section('page-title', __('Delivery Notes'))

@section('content')
<x-page-header :title="__('Delivery Notes')" :subtitle="__('Goods handed over with price and discount, no tax. Stock leaves when the note is issued.')">
    <x-slot:actions>
        @can('create', \App\Models\DeliveryNote::class)
            <x-btn href="{{ route('branch.pos.show', ['kind' => 'delivery_note']) }}">{{ __('New Delivery Note') }}</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" :placeholder="__('Search number or client…')" :aria-label="__('Search delivery notes')" />
    <select name="status" class="input-field" aria-label="{{ __('Filter by status') }}">
        <option value="">{{ __('All statuses') }}</option>
        @foreach(\App\Enums\DeliveryNoteStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('branch.delivery-notes.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($notes->isEmpty())
        <div class="panel__body">
            <x-empty-state
                :title="__('No delivery notes yet')"
                :message="__('Open POS, choose Delivery Note and add the items to hand over.')"
                icon="local_shipping"
            >
                <x-slot:actions>
                    @can('create', \App\Models\DeliveryNote::class)
                        <x-btn href="{{ route('branch.pos.show', ['kind' => 'delivery_note']) }}">{{ __('New Delivery Note') }}</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Delivery note') }}</th>
                        <th>{{ __('Client') }}</th>
                        <th class="table-admin__num">{{ __('Lines') }}</th>
                        <th class="table-admin__num">{{ __('Total') }}</th>
                        <th>{{ __('Invoice') }}</th>
                        <th>{{ __('Payment') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($notes as $note)
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('branch.delivery-notes.show', $note)"
                                    :title="$note->delivery_note_number"
                                    :meta="setting_date($note->delivery_date)"
                                    icon="local_shipping"
                                />
                            </td>
                            <td>{{ $note->customer_name_snapshot }}</td>
                            <td class="table-admin__num">{{ $note->items_count }}</td>
                            <td class="table-admin__num table-admin__num--strong">{{ setting_money($note->grand_total) }}</td>
                            <td>{{ $note->invoice?->invoice_number ?? '—' }}</td>
                            <td><x-status-badge :status="$note->payment_status" /></td>
                            <td><x-status-badge :status="$note->status" /></td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('branch.delivery-notes.show', $note)" />
                                    @if(auth()->user()->hasPermission('delivery_notes.delete'))
                                        <x-delete-record type="delivery-notes" :record="$note" :icon="true" text="{{ __('If the note is still open, its goods return to stock and it moves to Trash.') }}" />
                                    @endif
                                </x-table.actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($notes->total() > 0)
            <div class="panel__footer">
                {{ $notes->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection