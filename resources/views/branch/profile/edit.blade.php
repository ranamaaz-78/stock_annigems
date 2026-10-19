{{-- My Profile — self-service name, phone, password change. --}}
@extends('layouts.branch')

@section('title', __('My Profile'))
@section('page-title', __('My Profile'))

@section('content')
<x-page-header :title="__('My Profile')" :subtitle="__('Update your personal details. Role and permissions are managed by your branch Admin.')" />

<div class="max-w-xl bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
    <form method="POST" action="{{ route('branch.profile.update') }}" data-validate-form data-loading-form class="space-y-4">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <x-form.input name="name" :label="__('Name')" required maxlength="150" :value="old('name', $user->name)" />
            <x-form.input name="phone" :label="__('Phone')" maxlength="30" :value="old('phone', $user->phone)" />
        </div>

        <div class="border-t border-outline-variant pt-4">
            <p class="font-label-caps text-on-surface mb-2">{{ __('Change password') }}</p>
            <p class="text-body-sm text-on-surface-variant mb-3">{{ __('Leave blank to keep your current password.') }}</p>
            <div class="grid grid-cols-1 gap-3">
                <x-form.input name="current_password" :label="__('Current password')" type="password" autocomplete="current-password" />
                <x-form.input name="new_password" :label="__('New password')" type="password" minlength="{{ \App\Support\Settings\PasswordRules::minLength() }}" autocomplete="new-password" />
                <p class="form-help">{{ \App\Support\Settings\PasswordRules::helpText() }}</p>
                <x-form.input name="new_password_confirmation" :label="__('Confirm new password')" type="password" minlength="{{ \App\Support\Settings\PasswordRules::minLength() }}" data-match="#new_password" autocomplete="new-password" />
            </div>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-body-sm border-t border-outline-variant pt-4">
            <div><dt class="text-on-surface-variant">{{ __('Email') }}</dt><dd class="mt-0.5">{{ $user->email }}</dd></div>
            <div><dt class="text-on-surface-variant">{{ __('Role') }}</dt><dd class="mt-0.5">{{ $user->role->label() }}</dd></div>
            <div><dt class="text-on-surface-variant">{{ __('Branch') }}</dt><dd class="mt-0.5">{{ $user->branch?->name }}</dd></div>
        </dl>

        <div class="flex justify-end pt-2 border-t border-outline-variant">
            <x-btn type="submit" :loading-text="__('Saving...')">{{ __('Save Profile') }}</x-btn>
        </div>
    </form>
</div>
@endsection
