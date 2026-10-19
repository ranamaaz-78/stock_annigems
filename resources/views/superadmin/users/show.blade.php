{{-- SuperAdmin user detail: profile, attribution snapshot, audit history. --}}
@extends('layouts.superadmin')

@php
    $inactiveSuffix = ($user->trashed() || $user->status === \App\Enums\UserStatus::INACTIVE)
        ? ' (inactive)'
        : '';
@endphp

@section('title', $user->name . $inactiveSuffix)
@section('page-title', $user->name . $inactiveSuffix)

@section('content')
<x-page-header :title="$user->name . $inactiveSuffix" :subtitle="$user->email">
    <x-slot:actions>
        <x-btn href="{{ route('superadmin.users.index') }}" variant="secondary">{{ __('Back to users') }}</x-btn>
        @if($user->role !== \App\Enums\UserRole::SUPERADMIN && $user->id !== auth()->id())
            @if($user->status === \App\Enums\UserStatus::ACTIVE && ! $user->trashed())
                <form method="POST" action="{{ route('superadmin.users.login-as', $user) }}" id="login-as-form" data-user-name="{{ $user->name }}" data-loading-form>
                    @csrf
                    <x-btn type="submit" :loading-text="__('Opening...')">
                        <span class="material-symbols-outlined icon icon--sm">login</span>
                        {{ __('Log in as :name', ['name' => $user->name]) }}
                    </x-btn>
                </form>
            @endif
            @if($user->status === \App\Enums\UserStatus::ACTIVE)
                <form method="POST" action="{{ route('superadmin.users.deactivate', $user) }}" id="deactivate-user-form" data-loading-form data-only-admin="{{ $isOnlyActiveAdmin ? '1' : '0' }}">
                    @csrf
                    <x-btn type="submit" variant="danger" :loading-text="__('Deactivating...')">{{ __('Deactivate') }}</x-btn>
                </form>
            @else
                <form method="POST" action="{{ route('superadmin.users.activate', $user) }}" data-loading-form>
                    @csrf
                    <x-btn type="submit" :loading-text="__('Activating...')">{{ __('Activate') }}</x-btn>
                </form>
            @endif
        @endif
    </x-slot:actions>
</x-page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <div class="lg:col-span-2 bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
        <p class="font-label-caps text-on-surface-variant mb-3">{{ __('Account') }}</p>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-body-sm">
            <div><dt class="text-on-surface-variant">{{ __('Status') }}</dt><dd class="mt-0.5"><x-status-badge :status="$user->status" /></dd></div>
            <div><dt class="text-on-surface-variant">{{ __('Role') }}</dt><dd class="mt-0.5">{{ $user->role->label() }}</dd></div>
            <div><dt class="text-on-surface-variant">{{ __('Branch') }}</dt><dd class="mt-0.5">{{ $user->branch?->name ?? '—' }}</dd></div>
            <div><dt class="text-on-surface-variant">{{ __('Phone') }}</dt><dd class="mt-0.5">{{ $user->phone ?: '—' }}</dd></div>
            <div><dt class="text-on-surface-variant">{{ __('Last login') }}</dt><dd class="font-data-mono mt-0.5">{{ $user->last_login_at ? setting_datetime($user->last_login_at) : __('Never') }}</dd></div>
            <div><dt class="text-on-surface-variant">{{ __('Must change password') }}</dt><dd class="mt-0.5">{{ $user->must_change_password ? __('Yes') : __('No') }}</dd></div>
        </dl>
        <x-attribution :record="$user" />
    </div>
    <div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
        <p class="font-label-caps text-on-surface-variant mb-2">{{ __('Note') }}</p>
        <p class="text-body-sm text-on-surface-variant">
            {{ __('Deactivating this account does not hide or reassign any records they created. Data belongs to the branch, not the user.') }}
        </p>
    </div>
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden">
    <div class="px-4 py-3 border-b border-outline-variant">
        <h2 class="font-headline-sm text-headline-sm">{{ __('Activity') }}</h2>
    </div>
    <div class="px-4 py-2">
        <x-audit-log :logs="$auditLogs" />
    </div>
</div>
@endsection

@push('scripts')
<script>
const loginAsForm = document.getElementById('login-as-form');
if (loginAsForm) {
    loginAsForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const ok = await window.AnnigemsAlert.confirmAction({
            title: {{ \Illuminate\Support\Js::from(__('Log in as :name?')) }}.replace(':name', loginAsForm.dataset.userName),
            text: @js(__('You will be logged out of Super Admin and signed in to this account, without a password, as if the owner had logged in. Log out of it to get back to the login page. This is recorded in the audit log.')),
            confirmButtonText: @js(__('Log in as user')),
            icon: 'question',
        });
        if (ok) loginAsForm.submit();
    });
}
const deactivateForm = document.getElementById('deactivate-user-form');
if (deactivateForm) {
    deactivateForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const isOnlyAdmin = deactivateForm.dataset.onlyAdmin === '1';
        const ok = await window.AnnigemsAlert.confirmAction({
            title: isOnlyAdmin ? @js(__('This is the only Admin for this branch')) : @js(__('Deactivate this user?')),
            text: isOnlyAdmin
                ? @js(__('The branch will have no Admin until a new one is assigned. No data will be lost — items, invoices, customers, staff accounts, reports, and audit history stay intact. Only management access is affected until you assign a replacement. Create the replacement Admin first if you have not already.'))
                : @js(__('They will not be able to log in until reactivated. Records they created stay intact and remain visible to the branch.')),
            confirmButtonText: 'Deactivate',
        });
        if (ok) deactivateForm.submit();
    });
}
</script>
@endpush
