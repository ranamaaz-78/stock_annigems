{{-- Session timed out — design/session_timed_out_standardized --}}
@extends('layouts.guest')
@section('title', __('Session Timed Out'))
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <svg class="mx-auto mb-6 w-40 h-40" viewBox="0 0 160 160" fill="none" aria-hidden="true">
            <circle cx="80" cy="80" r="48" fill="#ecf5fe" stroke="#817563" stroke-width="2"/>
            <path d="M80 50 V80 H105" stroke="#785600" stroke-width="5" stroke-linecap="round"/>
            <path d="M110 40 L120 55 L105 55 Z" fill="#ba1a1a"/>
        </svg>
        <p class="font-label-caps text-primary mb-2">{{ __('Session expired') }}</p>
        <h1 class="font-headline-sm text-headline-sm">{{ __('Your session timed out') }}</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">{{ __('For your security, you were signed out after a period of inactivity.') }}</p>
        <a href="{{ route('login') }}" class="btn-primary inline-flex">{{ __('Sign in again') }}</a>
    </div>
</div>
@endsection
