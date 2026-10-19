{{-- 401 Unauthorized — design/401_unauthorized_standardized --}}
@extends('layouts.guest')
@section('title', __('401 Unauthorized'))
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <svg class="mx-auto mb-6 w-40 h-40" viewBox="0 0 160 160" fill="none" aria-hidden="true">
            <rect x="40" y="60" width="80" height="70" rx="8" fill="#e6eff8" stroke="#817563" stroke-width="2"/>
            <path d="M55 60 V48 a25 25 0 0 1 50 0 V60" stroke="#785600" stroke-width="4" fill="none" stroke-linecap="round"/>
            <circle cx="80" cy="95" r="8" fill="#785600"/>
            <rect x="76" y="100" width="8" height="16" rx="2" fill="#785600"/>
        </svg>
        <p class="font-label-caps text-primary mb-2">{{ __('Error 401') }}</p>
        <h1 class="font-headline-sm text-headline-sm">{{ __('Unauthorized') }}</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">{{ __('You need to sign in to access this area.') }}</p>
        <a href="{{ route('login') }}" class="btn-primary inline-flex">{{ __('Go to login') }}</a>
    </div>
</div>
@endsection
