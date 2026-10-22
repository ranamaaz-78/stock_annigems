{{-- Performa list for the current branch. --}}
@extends('layouts.branch')

@section('title', __('Performas'))
@section('page-title', __('Performas'))

@section('content')
<x-page-header :title="__('Performas')" :subtitle="__('Goods issued on credit against a creditor. Prices are captured when they pay.')">
    <x-slot:actions>
        @can('create', \App\Models\Performa::class)
            <x-btn href="{{ route('branch.performas.create') }}">{{ __('New Performa') }}</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" :placeholder="__('Search number or creditor…')" :aria-label="__('Search performas')" />
    <select name="status" class="input-field" aria-label="{{ __('Filter by status') }}">
        <option value="">{{ __('All statuses') }}</option>
        @foreach(\App\Enums\PerformaStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('branch.performas.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($performas->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No performas yet')" :message="__('Register a creditor, then issue a performa when they take jewellery or stones on credit.')" icon="assignment">
                <x-slot:actions>
                    @can('create', \App\Models\Performa::class)
                        <x-btn href="{{ route('branch.performas.create') }}">{{ __('New Performa') }}</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Performa') }}</th>
                        <th>{{ __('Creditor') }}</th>
                        <th class="table-admin__num">{{ __('Lines') }}</th>
                        <th>{{ __('Outstanding') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Created by') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($performas as $performa)
                        @php $outstanding = $performa->outstandingSummary(); @endphp
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('branch.performas.show', $performa)"
                                    :title="$performa->performa_number"
                                    :meta="setting_date($performa->performa_date)"
                                    icon="assignment"
                                />
                            </td>
                            <td>{{ $performa->displayCreditorName() }}</td>
                            <td class="table-admin__num">{{ $performa->items_count }}</td>
                            <td>
                                <span class="font-data-mono">{{ $outstanding['jewellery'] }} {{ __('pc') }}</span>
                                <span class="table-meta">{{ $outstanding['stones'] }} {{ __('st') }}</span>
                            </td>
                            <td><x-status-badge :status="$performa->status" /></td>
                            <td>
                                <x-audit-actor
                                    :user="$performa->creator"
                                    :role="$performa->created_by_role"
                                    :at="$performa->created_at"
                                />
                            </td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('branch.performas.show', $performa)" />
                                    @if(auth()->user()->hasPermission('performas.delete'))
                                        <x-delete-record type="performas" :record="$performa" :icon="true" text="{{ __('Unpaid goods return to stock and the performa moves to Trash.') }}" />
                                    @endif
                                </x-table.actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($performas->total() > 0)
            <div class="panel__footer">
                {{ $performas->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
