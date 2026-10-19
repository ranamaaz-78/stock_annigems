{{-- Branch staff users list (branch Admin view). --}}
@extends('layouts.branch')

@section('title', __('Users'))
@section('page-title', __('Users'))

@section('content')
<x-page-header :title="__('Users')" :subtitle="__('Staff accounts for your branch. Creating staff is optional — you can run everything alone as Admin.')">
    @can('create', \App\Models\User::class)
        <x-slot:actions>
            <x-btn href="{{ route('branch.users.create') }}">{{ __('Add User') }}</x-btn>
        </x-slot:actions>
    @endcan
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" :placeholder="__('Search name or email…')" :aria-label="__('Search users')" />
    <select name="role" class="input-field" aria-label="{{ __('Role') }}">
        <option value="">{{ __('All roles') }}</option>
        @foreach($roles as $role)
            <option value="{{ $role->name }}" @selected(($filters['role'] ?? '') === $role->name)>{{ $role->display_name }}</option>
        @endforeach
    </select>
    <select name="status" class="input-field" aria-label="{{ __('Status') }}">
        <option value="">{{ __('All statuses') }}</option>
        <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('Active') }}</option>
        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('Inactive') }}</option>
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('branch.users.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($users->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No staff users')" :message="__('Optional — add Manager, Cashier, or Inventory Staff when you need them.')" icon="group" />
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Staff') }}</th>
                        <th>{{ __('Role') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Last login') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $staff)
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('branch.users.show', $staff)"
                                    :title="$staff->name"
                                    :meta="$staff->email"
                                    :mark="true"
                                >
                                    @if($staff->trashed() || $staff->status === \App\Enums\UserStatus::INACTIVE)
                                        <span class="table-meta">{{ __('Inactive') }}</span>
                                    @endif
                                </x-table.identity>
                            </td>
                            <td>{{ $staff->role->label() }}</td>
                            <td><x-status-badge :status="$staff->status" /></td>
                            <td class="font-data-mono">{{ $staff->last_login_at ? setting_datetime($staff->last_login_at) : __('Never') }}</td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('branch.users.show', $staff)" />
                                </x-table.actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($users->hasPages())
            <div class="panel__footer">
                {{ $users->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
