{{-- SuperAdmin users list across all branches. --}}
@extends('layouts.superadmin')

@section('title', __('Users'))
@section('page-title', __('Users'))

@section('content')
<x-page-header :title="__('Users')" :subtitle="__('All accounts across branches. Create a replacement Admin before deactivating the outgoing one.')">
    <x-slot:actions>
        <x-btn href="{{ route('superadmin.users.create-admin') }}">{{ __('Create Branch Admin') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" :placeholder="__('Search name or email…')" :aria-label="__('Search users')" />
    <select name="branch" class="input-field" aria-label="{{ __('Branch') }}">
        <option value="">{{ __('All branches') }}</option>
        @foreach($branches as $branch)
            <option value="{{ $branch->uuid }}" @selected(($filters['branch'] ?? '') == $branch->uuid)>{{ $branch->name }}</option>
        @endforeach
    </select>
    <select name="role" class="input-field" aria-label="{{ __('Role') }}">
        <option value="">{{ __('All roles') }}</option>
        @foreach($roles as $value => $label)
            <option value="{{ $value }}" @selected(($filters['role'] ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="status" class="input-field" aria-label="{{ __('Status') }}">
        <option value="">{{ __('All statuses') }}</option>
        <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('Active') }}</option>
        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('Inactive') }}</option>
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('superadmin.users.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($users->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No users found')" :message="__('Create a Branch Admin after adding a branch.')" icon="group" />
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Staff') }}</th>
                        <th>{{ __('Role') }}</th>
                        <th>{{ __('Branch') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Last login') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('superadmin.users.show', $user)"
                                    :title="$user->name"
                                    :meta="$user->email"
                                    :mark="true"
                                >
                                    @if($user->trashed() || $user->status === \App\Enums\UserStatus::INACTIVE)
                                        <span class="table-meta">{{ __('Inactive') }}</span>
                                    @endif
                                </x-table.identity>
                            </td>
                            <td>{{ $user->role->label() }}</td>
                            <td>{{ $user->branch?->name ?? '—' }}</td>
                            <td><x-status-badge :status="$user->status" /></td>
                            <td class="font-data-mono">{{ $user->last_login_at ? setting_datetime($user->last_login_at) : __('Never') }}</td>
                            <td class="table-admin__actions-col">
                                @if($user->role !== \App\Enums\UserRole::SUPERADMIN && $user->id !== auth()->id())
                                    <x-table.actions>
                                        @if($user->status === \App\Enums\UserStatus::ACTIVE && ! $user->trashed())
                                            {{-- One click, no email or password: sign in as this account. --}}
                                            <form method="POST" action="{{ route('superadmin.users.login-as', $user) }}" class="inline js-confirm-login-as" data-user-name="{{ $user->name }}" data-loading-form>
                                                @csrf
                                                <button type="submit" class="btn btn--primary btn--sm btn--login-as" title="{{ __('Log in as :name', ['name' => $user->name]) }}" aria-label="{{ __('Log in as :name', ['name' => $user->name]) }}">
                                                    <span class="material-symbols-outlined icon icon--xs">login</span>
                                                    {{ __('Log in') }}
                                                </button>
                                            </form>
                                        @endif
                                        @if($user->status === \App\Enums\UserStatus::ACTIVE)
                                            <form method="POST" action="{{ route('superadmin.users.deactivate', $user) }}" class="inline js-confirm-deactivate" data-loading-form data-only-admin="{{ !empty($user->is_only_active_admin) ? '1' : '0' }}">
                                                @csrf
                                                <button type="submit" class="table-admin__action table-admin__action--danger" title="{{ __('Deactivate') }}" aria-label="{{ __('Deactivate') }}">
                                                    <span class="material-symbols-outlined icon icon--sm">block</span>
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('superadmin.users.activate', $user) }}" class="inline" data-loading-form>
                                                @csrf
                                                <button type="submit" class="table-admin__action" title="{{ __('Activate') }}" aria-label="{{ __('Activate') }}">
                                                    <span class="material-symbols-outlined icon icon--sm">check_circle</span>
                                                </button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('superadmin.users.reset-password', $user) }}" class="inline js-confirm-reset" data-loading-form>
                                            @csrf
                                            <button type="submit" class="table-admin__action" title="{{ __('Reset password') }}" aria-label="{{ __('Reset password') }}">
                                                <span class="material-symbols-outlined icon icon--sm">lock_reset</span>
                                            </button>
                                        </form>
                                    </x-table.actions>
                                @else
                                    <span class="table-meta">—</span>
                                @endif
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

@push('scripts')
<script>
document.querySelectorAll('.js-confirm-deactivate').forEach((form) => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const isOnlyAdmin = form.dataset.onlyAdmin === '1';
        const ok = await window.AnnigemsAlert.confirmAction({
            title: isOnlyAdmin ? @js(__('This is the only Admin for this branch')) : @js(__('Deactivate this user?')),
            text: isOnlyAdmin
                ? @js(__('The branch will have no Admin until a new one is assigned. No data will be lost — items, invoices, customers, staff accounts, reports, and audit history stay intact. Only management access is affected until you assign a replacement. Create the replacement Admin first if you have not already.'))
                : @js(__('They will not be able to log in until reactivated. Records they created stay intact and remain visible to the branch.')),
            confirmButtonText: 'Deactivate',
        });
        if (ok) form.submit();
    });
});
document.querySelectorAll('.js-confirm-login-as').forEach((form) => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const ok = await window.AnnigemsAlert.confirmAction({
            title: {{ \Illuminate\Support\Js::from(__('Log in as :name?')) }}.replace(':name', form.dataset.userName),
            text: @js(__('You will be logged out of Super Admin and signed in to this account, without a password, as if the owner had logged in. Log out of it to get back to the login page. This is recorded in the audit log.')),
            confirmButtonText: @js(__('Log in as user')),
            icon: 'question',
        });
        if (ok) form.submit();
    });
});
document.querySelectorAll('.js-confirm-reset').forEach((form) => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const ok = await window.AnnigemsAlert.confirmAction({
            title: @js(__('Reset this password?')),
            text: @js(__('A new temporary password will be generated. The user must change it on next login.')),
            confirmButtonText: @js(__('Reset password')),
            icon: 'question',
        });
        if (ok) form.submit();
    });
});
</script>
@endpush
