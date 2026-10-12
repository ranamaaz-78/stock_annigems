{{-- SuperAdmin users list across all branches. --}}
@extends('layouts.superadmin')

@section('title', 'Users')
@section('page-title', 'Users')

@section('content')
<x-page-header title="Users" subtitle="All accounts across branches. Create a replacement Admin before deactivating the outgoing one.">
    <x-slot:actions>
        <x-btn href="{{ route('superadmin.users.create-admin') }}">Create Branch Admin</x-btn>
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="search" :value="$filters['search'] ?? ''" placeholder="Search name or email…" aria-label="Search users" />
    <select name="branch" class="input-field" aria-label="Branch">
        <option value="">All branches</option>
        @foreach($branches as $branch)
            <option value="{{ $branch->uuid }}" @selected(($filters['branch'] ?? '') == $branch->uuid)>{{ $branch->name }}</option>
        @endforeach
    </select>
    <select name="role" class="input-field" aria-label="Role">
        <option value="">All roles</option>
        @foreach($roles as $value => $label)
            <option value="{{ $value }}" @selected(($filters['role'] ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="status" class="input-field" aria-label="Status">
        <option value="">All statuses</option>
        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">Filter</x-btn>
        <a href="{{ route('superadmin.users.index') }}" class="btn btn--secondary">Reset</a>
    </div>
</form>

<div class="panel">
    @if($users->isEmpty())
        <div class="panel__body">
            <x-empty-state title="No users found" message="Create a Branch Admin after adding a branch." icon="group" />
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Role</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th class="table-admin__actions-col">Actions</th>
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
                                        <span class="table-meta">Inactive</span>
                                    @endif
                                </x-table.identity>
                            </td>
                            <td>{{ $user->role->label() }}</td>
                            <td>{{ $user->branch?->name ?? '—' }}</td>
                            <td><x-status-badge :status="$user->status" /></td>
                            <td class="font-data-mono">{{ $user->last_login_at ? setting_datetime($user->last_login_at) : 'Never' }}</td>
                            <td class="table-admin__actions-col">
                                @if($user->role !== \App\Enums\UserRole::SUPERADMIN && $user->id !== auth()->id())
                                    <x-table.actions>
                                        @if($user->status === \App\Enums\UserStatus::ACTIVE && ! $user->trashed())
                                            {{-- One click, no email or password: sign in as this account. --}}
                                            <form method="POST" action="{{ route('superadmin.users.login-as', $user) }}" class="inline js-confirm-login-as" data-user-name="{{ $user->name }}" data-loading-form>
                                                @csrf
                                                <button type="submit" class="btn btn--primary btn--sm btn--login-as" title="Log in as {{ $user->name }}" aria-label="Log in as {{ $user->name }}">
                                                    <span class="material-symbols-outlined icon icon--xs">login</span>
                                                    Log in
                                                </button>
                                            </form>
                                        @endif
                                        @if($user->status === \App\Enums\UserStatus::ACTIVE)
                                            <form method="POST" action="{{ route('superadmin.users.deactivate', $user) }}" class="inline js-confirm-deactivate" data-loading-form data-only-admin="{{ !empty($user->is_only_active_admin) ? '1' : '0' }}">
                                                @csrf
                                                <button type="submit" class="table-admin__action table-admin__action--danger" title="Deactivate" aria-label="Deactivate">
                                                    <span class="material-symbols-outlined icon icon--sm">block</span>
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('superadmin.users.activate', $user) }}" class="inline" data-loading-form>
                                                @csrf
                                                <button type="submit" class="table-admin__action" title="Activate" aria-label="Activate">
                                                    <span class="material-symbols-outlined icon icon--sm">check_circle</span>
                                                </button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('superadmin.users.reset-password', $user) }}" class="inline js-confirm-reset" data-loading-form>
                                            @csrf
                                            <button type="submit" class="table-admin__action" title="Reset password" aria-label="Reset password">
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
            title: isOnlyAdmin ? 'This is the only Admin for this branch' : 'Deactivate this user?',
            text: isOnlyAdmin
                ? 'The branch will have no Admin until a new one is assigned. No data will be lost — items, invoices, customers, staff accounts, reports, and audit history stay intact. Only management access is affected until you assign a replacement. Create the replacement Admin first if you have not already.'
                : 'They will not be able to log in until reactivated. Records they created stay intact and remain visible to the branch.',
            confirmButtonText: 'Deactivate',
        });
        if (ok) form.submit();
    });
});
document.querySelectorAll('.js-confirm-login-as').forEach((form) => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const ok = await window.AnnigemsAlert.confirmAction({
            title: 'Log in as ' + form.dataset.userName + '?',
            text: 'You will be logged out of Super Admin and signed in to this account, without a password, as if the owner had logged in. Log out of it to get back to the login page. This is recorded in the audit log.',
            confirmButtonText: 'Log in as user',
            icon: 'question',
        });
        if (ok) form.submit();
    });
});
document.querySelectorAll('.js-confirm-reset').forEach((form) => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const ok = await window.AnnigemsAlert.confirmAction({
            title: 'Reset this password?',
            text: 'A new temporary password will be generated. The user must change it on next login.',
            confirmButtonText: 'Reset password',
            icon: 'question',
        });
        if (ok) form.submit();
    });
});
</script>
@endpush
