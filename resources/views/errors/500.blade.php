{{-- 500 Internal Server Error — design/500_internal_server_error_standardized --}}
@extends('layouts.guest')
@section('title', '500 Server Error')
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <svg class="mx-auto mb-6 w-40 h-40" viewBox="0 0 160 160" fill="none" aria-hidden="true">
            <rect x="40" y="40" width="80" height="80" rx="10" fill="#e6eff8" stroke="#817563" stroke-width="2"/>
            <path d="M60 70 H100 M60 90 H90" stroke="#5d5e61" stroke-width="4" stroke-linecap="round"/>
            <circle cx="115" cy="45" r="14" fill="#ffdad6" stroke="#ba1a1a" stroke-width="2"/>
            <text x="115" y="50" text-anchor="middle" fill="#ba1a1a" font-size="16" font-weight="700">!</text>
        </svg>
        <p class="font-label-caps text-primary mb-2">Error 500</p>
        <h1 class="font-headline-sm text-headline-sm">Something went wrong</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">An unexpected error occurred. Please try again or contact support.</p>
        <a href="{{ route('login') }}" class="btn-primary inline-flex">Back to login</a>
    </div>
</div>
@endsection
