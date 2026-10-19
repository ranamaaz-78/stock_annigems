{{-- Forced change-password screen when must_change_password is true. --}}
@extends('layouts.guest')

@section('title', __('Change password').' — '.setting('company_name', 'Aanigemas'))

@section('content')
@php
    $min = \App\Support\Settings\PasswordRules::minLength();
@endphp
<div class="guest-page">
    <div class="guest-card">
        <x-shell.language-switch class="guest-card__lang" />
        <div class="guest-card__header">
            <span class="material-symbols-outlined icon icon--lg icon--primary">lock_reset</span>
            <div class="guest-card__header-text">
                <h1 class="guest-card__title text-headline-sm">{{ __('Set a new password') }}</h1>
                <p class="guest-card__subtitle text-body-sm">{{ __('You must change your temporary password before continuing.') }}</p>
            </div>
        </div>

        <x-alert type="info" class="login-form__alert">
            {{ \App\Support\Settings\PasswordRules::helpText() }}
        </x-alert>

        @if($errors->any())
            <x-alert type="error" class="login-form__alert">{{ $errors->first() }}</x-alert>
        @endif

        <form method="POST" action="{{ route('password.change.update') }}" data-validate-form data-loading-form class="guest-card__stack">
            @csrf
            <x-form.input name="password" :label="__('New Password')" type="password" required minlength="{{ $min }}" autocomplete="new-password" />
            <x-form.input name="password_confirmation" :label="__('Confirm Password')" type="password" required minlength="{{ $min }}" data-match="#password" data-error-match="{{ __('Passwords do not match.') }}" autocomplete="new-password" />
            <x-btn type="submit" block :loading-text="__('Updating...')">{{ __('Update password') }}</x-btn>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="guest-card__footer" data-loading-form>
            @csrf
            <button type="submit" class="form-link">{{ __('Sign out') }}</button>
        </form>
    </div>
</div>
@endsection
