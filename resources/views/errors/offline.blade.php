{{-- Offline / no internet — design/no_internet_connection_standardized --}}
@extends('layouts.guest')
@section('title', __('No Internet Connection'))
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <svg class="mx-auto mb-6 w-40 h-40" viewBox="0 0 160 160" fill="none" aria-hidden="true">
            <path d="M40 100 C55 70, 105 70, 120 100" stroke="#d3c4af" stroke-width="6" fill="none" stroke-linecap="round"/>
            <path d="M55 110 C65 95, 95 95, 105 110" stroke="#d3c4af" stroke-width="6" fill="none" stroke-linecap="round"/>
            <circle cx="80" cy="122" r="6" fill="#785600"/>
            <path d="M50 50 L110 110" stroke="#ba1a1a" stroke-width="5" stroke-linecap="round"/>
        </svg>
        <p class="font-label-caps text-primary mb-2">{{ __('Offline') }}</p>
        <h1 class="font-headline-sm text-headline-sm">{{ __('No internet connection') }}</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">{{ __('Check your network connection, then try again.') }}</p>
        <button type="button" class="btn-primary inline-flex" onclick="window.location.reload()">{{ __('Retry') }}</button>
    </div>
</div>
@endsection
