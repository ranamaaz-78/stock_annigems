{{-- Create Branch Admin form with auto/manual password and optional email. --}}
@extends('layouts.superadmin')

@section('title', __('Create Branch Admin'))
@section('page-title', __('Create Branch Admin'))

@section('content')
@php
    $preselect = old('branch', request('branch'));
    $branchOptions = $branches->mapWithKeys(fn ($b) => [$b->uuid => $b->name . ' (' . $b->code . ')'])->all();
@endphp

<x-page-header :title="__('Create Branch Admin')" :subtitle="__('Create the replacement Admin first, then deactivate the outgoing one so the branch is never left unmanaged.')" />

@if($branches->isEmpty())
    <x-alert type="warning" class="mb-4">
        {{ __('There are no active branches. Create or activate a branch before assigning an Admin.') }}
    </x-alert>
@else
    <x-alert type="info" class="mb-4">
        {{ __('A branch Admin can run the entire system alone — staff accounts are optional. If this branch already has an Admin, you are creating a replacement. Deactivate the outgoing Admin only after this account is ready. No data is reassigned or lost.') }}
    </x-alert>
@endif

<div class="max-w-2xl bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
    <form method="POST" action="{{ route('superadmin.users.store-admin') }}" data-validate-form data-loading-form class="space-y-4" id="create-admin-form">
        @csrf

        <x-form.select
            name="branch"
            :label="__('Branch')"
            :options="$branchOptions"
            :value="$preselect"
            required
            :placeholder="__('Select branch')"
        />

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <x-form.input name="name" :label="__('Admin Name')" required maxlength="150" />
            <x-form.input name="email" :label="__('Email')" type="email" required maxlength="255" />
            <x-form.input name="phone" :label="__('Phone')" maxlength="30" />
        </div>

        <div data-field>
            <p class="font-label-caps text-on-surface mb-2">{{ __('Password') }}</p>
            <div class="flex flex-col gap-2 text-body-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="password_mode" value="auto" class="text-primary focus:ring-primary" @checked(old('password_mode', 'auto') === 'auto')>
                    {{ __('Auto-generate a secure password') }}
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="password_mode" value="manual" class="text-primary focus:ring-primary" @checked(old('password_mode') === 'manual')>
                    {{ __('Set password manually') }}
                </label>
            </div>
        </div>

        <div id="manual-password-fields" class="grid grid-cols-1 sm:grid-cols-2 gap-3 {{ old('password_mode') === 'manual' ? '' : 'hidden' }}">
            <x-form.input name="password" :label="__('Password')" type="password" minlength="{{ \App\Support\Settings\PasswordRules::minLength() }}" autocomplete="new-password" />
            <p class="form-help">{{ \App\Support\Settings\PasswordRules::helpText() }}</p>
            <x-form.input name="password_confirmation" :label="__('Confirm Password')" type="password" minlength="{{ \App\Support\Settings\PasswordRules::minLength() }}" data-match="#password" autocomplete="new-password" />
        </div>

        <label class="inline-flex items-center gap-2 text-body-sm">
            <input type="checkbox" name="send_credentials_email" value="1" class="rounded border-outline-variant text-primary focus:ring-primary" @checked(old('send_credentials_email'))>
            {{ __('Send credentials via email (uses configured mailer)') }}
        </label>

        <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <x-btn href="{{ route('superadmin.users.index') }}" variant="secondary">{{ __('Cancel') }}</x-btn>
            <x-btn type="submit" :loading-text="__('Creating...')" :disabled="$branches->isEmpty()">Create Admin</x-btn>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
const form = document.getElementById('create-admin-form');
const manualFields = document.getElementById('manual-password-fields');
form?.querySelectorAll('input[name="password_mode"]').forEach((radio) => {
    radio.addEventListener('change', () => {
        const isManual = form.querySelector('input[name="password_mode"]:checked')?.value === 'manual';
        manualFields.classList.toggle('hidden', !isManual);
        manualFields.querySelectorAll('input').forEach((input) => {
            input.required = isManual;
        });
    });
});
</script>
@endpush
