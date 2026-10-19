{{-- 419 Page Expired — design/419_page_expired_standardized --}}
@extends('layouts.guest')
@section('title', __('419 Page Expired'))
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <svg class="mx-auto mb-6 w-40 h-40" viewBox="0 0 160 160" fill="none" aria-hidden="true">
            <circle cx="80" cy="80" r="48" fill="#ecf5fe" stroke="#817563" stroke-width="2"/>
            <circle cx="80" cy="80" r="36" stroke="#785600" stroke-width="3" fill="none"/>
            <path d="M80 55 V80 L98 92" stroke="#785600" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <p class="font-label-caps text-primary mb-2">{{ __('Error 419') }}</p>
        <h1 class="font-headline-sm text-headline-sm">{{ __('Page expired') }}</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">{{ __('Your session token expired. Refresh and try again.') }}</p>
        <a href="{{ url()->previous() }}" class="btn-primary inline-flex">{{ __('Try again') }}</a>
    </div>
</div>
@endsection
