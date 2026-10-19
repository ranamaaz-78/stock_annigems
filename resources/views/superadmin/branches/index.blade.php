{{-- Branch list with search/filters. Design: design/branch_management. --}}
@extends('layouts.superadmin')

@section('title', __('Branches'))
@section('page-title', __('Branch Management'))

@section('content')
<x-page-header :title="__('Branches')" :subtitle="__('Create and manage boutique locations.')">
    <x-slot:actions>
        <x-btn href="{{ route('superadmin.branches.create') }}">{{ __('+ Add Branch') }}</x-btn>
        <x-btn href="{{ route('superadmin.users.create-admin') }}" variant="secondary">{{ __('Create Branch Admin') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<form method="GET" action="{{ route('superadmin.branches.index') }}" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" :placeholder="__('Search branches…')" :aria-label="__('Search branches')" />
    <select name="status" class="input-field" aria-label="{{ __('Status') }}">
        <option value="">{{ __('All statuses') }}</option>
        <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('Active') }}</option>
        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('Inactive') }}</option>
    </select>
    <select name="city" class="input-field" aria-label="{{ __('City') }}">
        <option value="">{{ __('All cities') }}</option>
        @foreach($cities as $city)
            <option value="{{ $city }}" @selected(($filters['city'] ?? '') === $city)>{{ $city }}</option>
        @endforeach
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('superadmin.branches.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($branches->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No branches found')" :message="__('Try adjusting filters or create a new branch.')" icon="store">
                <x-slot:actions>
                    <x-btn href="{{ route('superadmin.branches.create') }}">{{ __('+ Add Branch') }}</x-btn>
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Branch') }}</th>
                        <th>{{ __('Address') }}</th>
                        <th>{{ __('Contact') }}</th>
                        <th>{{ __('Admin Assigned') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($branches as $branch)
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('superadmin.branches.show', $branch)"
                                    :title="$branch->name"
                                    :meta="$branch->code.' · '.$branch->city"
                                    icon="store"
                                />
                            </td>
                            <td>{{ $branch->address }}</td>
                            <td>
                                <span class="font-data-mono">{{ $branch->contact_number }}</span>
                                <span class="table-meta">{{ $branch->email }}</span>
                            </td>
                            <td>{{ $branch->activeAdmins->pluck('name')->join(', ') ?: __('Unassigned') }}</td>
                            <td><x-status-badge :status="$branch->status" /></td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('superadmin.branches.show', $branch)" />
                                    <x-table.action :href="route('superadmin.branches.edit', $branch)" icon="edit" :label="__('Edit')" />
                                    @if($branch->status === \App\Enums\BranchStatus::ACTIVE)
                                        <form method="POST" action="{{ route('superadmin.branches.deactivate', $branch) }}" class="inline" data-confirm-deactivate data-loading-form>
                                            @csrf
                                            <button type="submit" class="table-admin__action table-admin__action--danger" title="{{ __('Deactivate') }}" aria-label="{{ __('Deactivate') }}" data-loading-text="…">
                                                <span class="material-symbols-outlined icon icon--sm">block</span>
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('superadmin.branches.activate', $branch) }}" class="inline" data-loading-form>
                                            @csrf
                                            <button type="submit" class="table-admin__action" title="{{ __('Activate') }}" aria-label="{{ __('Activate') }}" data-loading-text="…">
                                                <span class="material-symbols-outlined icon icon--sm">check_circle</span>
                                            </button>
                                        </form>
                                    @endif
                                </x-table.actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($branches->hasPages())
            <div class="panel__footer">
                {{ $branches->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-confirm-deactivate]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const ok = await window.AnnigemsAlert.confirmAction({
            title: @js(__('Deactivate this branch?')),
            text: @js(__('This will block all users of this branch from logging in. You can reactivate later.')),
            confirmButtonText: 'Deactivate',
        });
        if (ok) {
            form.submit();
        } else if (form._loadingController) {
            form._loadingController.restore();
        }
    });
});
</script>
@endpush
