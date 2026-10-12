{{-- Forgot password request form (SCSS guest card). --}}
@extends('layouts.guest')

@section('title', 'Forgot Password — '.setting('company_name', 'Annigems'))

@section('content')
<div class="guest-page">
    <div class="guest-card">
        <h1 class="guest-card__title text-headline-sm">Forgot password</h1>
        <p class="guest-card__subtitle text-body-sm">Enter your email and we will send a reset link if an account exists.</p>

        <form method="POST" action="{{ route('password.email') }}" data-validate-form data-loading-form class="guest-card__stack">
            @csrf
            <x-form.input name="email" label="Email" type="email" required autocomplete="username" />
            <x-btn type="submit" block loading-text="Sending...">Send reset link</x-btn>
        </form>

        <p class="guest-card__footer">
            <a href="{{ route('login') }}" class="form-link">Back to login</a>
        </p>
    </div>
</div>
@endsection
