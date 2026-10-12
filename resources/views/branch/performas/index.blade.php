{{-- Performa list for the current branch. --}}
@extends('layouts.branch')

@section('title', 'Performas')
@section('page-title', 'Performas')

@section('content')
<x-page-header title="Performas" subtitle="Goods issued on credit against a creditor. Prices are captured when they pay.">
    <x-slot:actions>
        @can('create', \App\Models\Performa::class)
            <x-btn href="{{ route('branch.performas.create') }}">New Performa</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" placeholder="Search number or creditor…" aria-label="Search performas" />
    <select name="status" class="input-field" aria-label="Filter by status">
        <option value="">All statuses</option>
        @foreach(\App\Enums\PerformaStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">Filter</x-btn>
        <a href="{{ route('branch.performas.index') }}" class="btn btn--secondary">Reset</a>
    </div>
</form>

<div class="panel">
    @if($performas->isEmpty())
        <div class="panel__body">
            <x-empty-state title="No performas yet" message="Register a creditor, then issue a performa when they take jewellery or stones on credit." icon="assignment">
                <x-slot:actions>
                    @can('create', \App\Models\Performa::class)
                        <x-btn href="{{ route('branch.performas.create') }}">New Performa</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>Performa</th>
                        <th>Creditor</th>
                        <th class="table-admin__num">Lines</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                        <th>Created by</th>
                        <th class="table-admin__actions-col">Actions</th>
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
                                <span class="font-data-mono">{{ $outstanding['jewellery'] }} pc</span>
                                <span class="table-meta">{{ $outstanding['stones'] }} st</span>
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
