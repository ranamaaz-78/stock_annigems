{{-- Creditor registry for the current branch. --}}
@extends('layouts.branch')

@section('title', 'Creditors')
@section('page-title', 'Creditors')

@section('content')
<x-page-header title="Creditors" subtitle="Parties who take goods on performa. Register them here, then issue a performa against them.">
    <x-slot:actions>
        @can('create', \App\Models\Creditor::class)
            <x-btn href="{{ route('branch.creditors.create') }}">Add Creditor</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" placeholder="Search name, phone, code…" aria-label="Search creditors" />
    <select name="status" class="input-field" aria-label="Filter by status">
        <option value="">All statuses</option>
        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">Filter</x-btn>
        <a href="{{ route('branch.creditors.index') }}" class="btn btn--secondary">Reset</a>
    </div>
</form>

<div class="panel">
    @if($creditors->isEmpty())
        <div class="panel__body">
            <x-empty-state title="No creditors found" message="Register a creditor before issuing a performa." icon="handshake">
                <x-slot:actions>
                    @can('create', \App\Models\Creditor::class)
                        <x-btn href="{{ route('branch.creditors.create') }}">Add Creditor</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>Creditor</th>
                        <th>Contact</th>
                        <th>City</th>
                        <th class="table-admin__num">Performas</th>
                        <th>Status</th>
                        <th class="table-admin__actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($creditors as $creditor)
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('branch.creditors.show', $creditor)"
                                    :title="$creditor->name"
                                    :meta="$creditor->creditor_code"
                                    :mark="true"
                                />
                            </td>
                            <td class="font-data-mono">{{ $creditor->phone }}</td>
                            <td>{{ $creditor->city ?? '—' }}</td>
                            <td class="table-admin__num">{{ $creditor->performas_count }}</td>
                            <td><x-status-badge :status="$creditor->status" /></td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('branch.creditors.show', $creditor)" />
                                </x-table.actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($creditors->total() > 0)
            <div class="panel__footer">
                {{ $creditors->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
