{{-- Reset password form from emailed token. --}}
@extends('layouts.guest')

@section('title', 'Reset Password — '.setting('company_name', 'Annigems'))

@section('content')
@php $min = \App\Support\Settings\PasswordRules::minLength(); @endphp
<div class="guest-page">
    <div class="guest-card">
        <h1 class="guest-card__title text-headline-sm">Reset password</h1>
        <p class="guest-card__subtitle text-body-sm">Choose a strong new password for your account.</p>

        @if($errors->any())
            <x-alert type="error" class="login-form__alert">{{ $errors->first() }}</x-alert>
        @endif

        <form method="POST" action="{{ route('password.update') }}" data-validate-form data-loading-form class="guest-card__stack">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-form.input name="email" label="Email" type="email" :value="$email" required autocomplete="username" />
            <x-form.input name="password" label="New Password" type="password" required minlength="{{ $min }}" autocomplete="new-password" />
            <x-form.input name="password_confirmation" label="Confirm Password" type="password" required minlength="{{ $min }}" data-match="#password" autocomplete="new-password" />
            <x-btn type="submit" block loading-text="Resetting...">Reset password</x-btn>
        </form>
    </div>
</div>
@endsection
