{{-- Reset password form from emailed token. --}}
@extends('layouts.guest')

@section('title', __('Reset password').' — '.setting('company_name', 'Aanigemas'))

@section('content')
@php $min = \App\Support\Settings\PasswordRules::minLength(); @endphp
<div class="guest-page">
    <div class="guest-card">
        <x-shell.language-switch class="guest-card__lang" />
        <h1 class="guest-card__title text-headline-sm">{{ __('Reset password') }}</h1>
        <p class="guest-card__subtitle text-body-sm">{{ __('Choose a strong new password for your account.') }}</p>

        @if($errors->any())
            <x-alert type="error" class="login-form__alert">{{ $errors->first() }}</x-alert>
        @endif

        <form method="POST" action="{{ route('password.update') }}" data-validate-form data-loading-form class="guest-card__stack">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-form.input name="email" :label="__('Email')" type="email" :value="$email" required autocomplete="username" />
            <x-form.input name="password" :label="__('New Password')" type="password" required minlength="{{ $min }}" autocomplete="new-password" />
            <x-form.input name="password_confirmation" :label="__('Confirm Password')" type="password" required minlength="{{ $min }}" data-match="#password" autocomplete="new-password" />
            <x-btn type="submit" block :loading-text="__('Resetting...')">{{ __('Reset password') }}</x-btn>
        </form>
    </div>
</div>
@endsection
