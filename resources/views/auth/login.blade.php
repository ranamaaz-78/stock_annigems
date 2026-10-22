{{--
    Login screen — shared by SuperAdmin and branch users.
    Visual source: design/login_screen/code.html
    Styles: resources/css/layout/_shell.scss + components/_forms.scss (no Tailwind visual hacks)
--}}
@extends('layouts.guest')

@section('title', __('Login').' — '.setting('company_name', 'Aani Gemas'))

@section('content')
<div class="login-page">
    <div class="login-shell">

        <div class="login-brand">
            <div
                class="login-brand__bg"
                style="background-image: url('https://lh3.googleusercontent.com/aida-public/AB6AXuDlLE4Zdk75MfvKyaBmWbf60CmIibtNNDaFXw9LkUZtPqEG4IcquTc2BRMso7S-DIxyaB1EIROZ7MkS9kG3w36ar0I0jMMsmEVwJLvJqQXdZ0UL9-j1n0dANdWyd4gPQI-sWxBrEelGEpgTWVavJSz3CJ4B9tFDUVwhjhdgtKJZw2HZh3wRKB8e3iYPx-8VzmXrb4_zBGGmASDPLus_hBgxnI7c2UPNutrPhrz3yurtiWAryxubOm1fNg')"
                role="img"
                aria-label="{{ __('Luxury jewellery macro photograph on dark slate') }}"
            ></div>
            <div class="login-brand__content">
                {{-- Dark panel: the gold monogram and a gold name read here; the full logo's navy lettering would not. --}}
                <img class="login-brand__logo" src="{{ brand_logo_url() }}" alt="">
                <h1 class="login-brand__title text-display">{{ setting('company_name', 'Aani Gemas') }}</h1>
                <p class="login-brand__tagline text-body-md">{{ __('Luxury Inventory Management') }}</p>
            </div>
        </div>

        <div class="login-form">
            <x-shell.language-switch class="login-form__lang" />

            <div class="login-form__mobile-brand">
                {{-- Light background here, so the full logo (with its navy name) fits. --}}
                <img class="login-form__mobile-logo" src="{{ brand_logo_url('full') }}" alt="{{ setting('company_name', 'Aani Gemas') }}">
            </div>

            <div class="login-form__header">
                <h2 class="login-form__title text-headline-sm">{{ __('Welcome back') }}</h2>
                <p class="login-form__subtitle text-body-sm">{{ __('Please enter your credentials to access the inventory system.') }}</p>
            </div>

            @if($errors->any())
                <div class="alert alert--error login-form__alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" data-validate-form data-loading-form class="login-form__stack">
                @csrf

                <div class="form-field" data-field>
                    <label class="form-label" for="email">{{ __('Email / Username') }}</label>
                    <div class="input-with-icon">
                        <span class="input-with-icon__icon">
                            <span class="material-symbols-outlined icon icon--sm icon--secondary">person</span>
                        </span>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            autocomplete="username"
                            placeholder="{{ __('admin@aanigemas.com') }}"
                            class="input-with-icon__field {{ $errors->has('email') ? 'is-invalid' : '' }}"
                        >
                    </div>
                    <p data-error-for="email" class="form-error {{ $errors->has('email') ? '' : 'is-hidden' }}">{{ $errors->first('email') }}</p>
                </div>

                <div class="form-field" data-field>
                    <label class="form-label" for="password">{{ __('Password') }}</label>
                    <div class="input-with-icon">
                        <span class="input-with-icon__icon">
                            <span class="material-symbols-outlined icon icon--sm icon--secondary">lock</span>
                        </span>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                            class="input-with-icon__field {{ $errors->has('password') ? 'is-invalid' : '' }}"
                        >
                    </div>
                    <p data-error-for="password" class="form-error {{ $errors->has('password') ? '' : 'is-hidden' }}">{{ $errors->first('password') }}</p>
                </div>

                <div class="login-form__row">
                    <label class="form-check" for="remember">
                        <input id="remember" name="remember" type="checkbox" value="1">
                        {{ __('Remember me') }}
                    </label>
                    <a href="{{ route('password.request') }}" class="form-link">{{ __('Forgot password?') }}</a>
                </div>

                <div class="login-form__submit">
                    <button
                        type="submit"
                        data-loading-text="{{ __('Signing in...') }}"
                        class="btn btn--primary btn--block btn--label-caps"
                    >
                        {{ __('Login') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
