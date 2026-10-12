{{-- Branch staff user detail with effective permissions and actions. --}}
@extends('layouts.branch')

@php
    $inactiveSuffix = ($user->trashed() || $user->status === \App\Enums\UserStatus::INACTIVE) ? ' (inactive)' : '';
@endphp

@section('title', $user->name . $inactiveSuffix)
@section('page-title', $user->name . $inactiveSuffix)

@section('content')
<x-page-header :title="$user->name . $inactiveSuffix" :subtitle="$user->email">
    <x-slot:actions>
        <x-btn href="{{ route('branch.users.index') }}" variant="secondary">Back</x-btn>
        @can('update', $user)
            <x-btn href="{{ route('branch.users.edit', $user) }}">Edit</x-btn>
        @endcan
        @can('toggleStatus', $user)
            @if($user->status === \App\Enums\UserStatus::ACTIVE)
                <form method="POST" action="{{ route('branch.users.deactivate', $user) }}" id="deactivate-user-form" data-loading-form class="inline">
                    @csrf
                    <x-btn type="submit" variant="danger" loading-text="Deactivating...">Deactivate</x-btn>
                </form>
            @else
                <form method="POST" action="{{ route('branch.users.activate', $user) }}" data-loading-form class="inline">
                    @csrf
                    <x-btn type="submit" loading-text="Activating...">Activate</x-btn>
                </form>
            @endif
        @endcan
        @can('resetPassword', $user)
            <form method="POST" action="{{ route('branch.users.reset-password', $user) }}" id="reset-password-form" data-loading-form class="inline">
                @csrf
                <x-btn type="submit" variant="secondary" loading-text="Resetting...">Reset Password</x-btn>
            </form>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <div class="lg:col-span-2 bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
        <p class="font-label-caps text-on-surface-variant mb-3">Profile</p>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-body-sm">
            <div><dt class="text-on-surface-variant">Status</dt><dd class="mt-0.5"><x-status-badge :status="$user->status" /></dd></div>
            <div><dt class="text-on-surface-variant">Role</dt><dd class="mt-0.5">{{ $user->role->label() }}</dd></div>
            <div><dt class="text-on-surface-variant">Phone</dt><dd class="mt-0.5">{{ $user->phone ?: '—' }}</dd></div>
            <div><dt class="text-on-surface-variant">Last login</dt><dd class="font-data-mono mt-0.5">{{ $user->last_login_at ? setting_datetime($user->last_login_at) : 'Never' }}</dd></div>
            <div><dt class="text-on-surface-variant">Created</dt><dd class="font-data-mono mt-0.5">{{ setting_date($user->created_at) }}</dd></div>
            <div><dt class="text-on-surface-variant">Must change password</dt><dd class="mt-0.5">{{ $user->must_change_password ? 'Yes' : 'No' }}</dd></div>
        </dl>
        <x-attribution :record="$user" />
    </div>

    <div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
        <p class="font-label-caps text-on-surface-variant mb-2">Effective permissions</p>
        <p class="text-body-sm text-on-surface-variant mb-3">Role defaults plus any overrides below.</p>
        @if(! empty($permissionBreakdown['revoked_overrides']))
            <p class="text-body-sm mb-2"><span class="font-medium">Revoked from role:</span> {{ implode(', ', $permissionBreakdown['revoked_overrides']) }}</p>
        @endif
        @if(! empty($permissionBreakdown['granted_overrides']))
            <p class="text-body-sm mb-2"><span class="font-medium">Granted beyond role:</span> {{ implode(', ', $permissionBreakdown['granted_overrides']) }}</p>
        @endif
        <ul class="text-body-sm space-y-1 max-h-64 overflow-y-auto">
            @forelse($permissionBreakdown['effective'] as $perm)
                <li>{{ $perm }}</li>
            @empty
                <li class="text-on-surface-variant">No permissions</li>
            @endforelse
        </ul>
    </div>
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden">
    <div class="px-4 py-3 border-b border-outline-variant">
        <h2 class="font-headline-sm text-headline-sm">Activity</h2>
    </div>
    <div class="px-4 py-2">
        <x-audit-log :logs="$auditEntries" />
    </div>
</div>
@endsection

@push('scripts')
<script>
const deactivateForm = document.getElementById('deactivate-user-form');
if (deactivateForm) {
    deactivateForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const ok = await window.AnnigemsAlert.confirmAction({
            title: 'Deactivate this user?',
            text: 'They will be logged out immediately and cannot sign in until reactivated.',
            confirmButtonText: 'Deactivate',
        });
        if (ok) deactivateForm.submit();
    });
}
const resetForm = document.getElementById('reset-password-form');
if (resetForm) {
    resetForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const ok = await window.AnnigemsAlert.confirmAction({
            title: 'Reset this password?',
            text: 'A new temporary password will be generated. The user must change it on next login.',
            confirmButtonText: 'Reset password',
            icon: 'question',
        });
        if (ok) resetForm.submit();
    });
}
</script>
@endpush
