{{-- Forgot password request form (SCSS guest card). --}}
@extends('layouts.guest')

@section('title', __('Forgot password').' — '.setting('company_name', 'Aani Gemas'))

@section('content')
<div class="guest-page">
    <div class="guest-card">
        <x-shell.language-switch class="guest-card__lang" />
        <h1 class="guest-card__title text-headline-sm">{{ __('Forgot password') }}</h1>
        <p class="guest-card__subtitle text-body-sm">{{ __('Enter your email and we will send a reset link if an account exists.') }}</p>

        <form method="POST" action="{{ route('password.email') }}" data-validate-form data-loading-form class="guest-card__stack">
            @csrf
            <x-form.input name="email" :label="__('Email')" type="email" required autocomplete="username" />
            <x-btn type="submit" block :loading-text="__('Sending...')">{{ __('Send reset link') }}</x-btn>
        </form>

        <p class="guest-card__footer">
            <a href="{{ route('login') }}" class="form-link">{{ __('Back to login') }}</a>
        </p>
    </div>
</div>
@endsection
