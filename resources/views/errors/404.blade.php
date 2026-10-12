{{-- 404 Not Found — design/404_page_not_found_standardized --}}
@extends('layouts.guest')
@section('title', '404 Not Found')
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <svg class="mx-auto mb-6 w-40 h-40" viewBox="0 0 160 160" fill="none" aria-hidden="true">
            <rect x="30" y="45" width="100" height="70" rx="8" fill="#e6eff8" stroke="#d3c4af" stroke-width="2"/>
            <circle cx="80" cy="80" r="18" stroke="#785600" stroke-width="4" fill="#ffdea6"/>
            <path d="M92 92 L110 110" stroke="#785600" stroke-width="4" stroke-linecap="round"/>
            <text x="80" y="86" text-anchor="middle" fill="#785600" font-size="14" font-family="Inter,sans-serif" font-weight="700">?</text>
        </svg>
        <p class="font-label-caps text-primary mb-2">Error 404</p>
        <h1 class="font-headline-sm text-headline-sm">Page not found</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">The page you are looking for does not exist or was moved.</p>
        <a href="{{ route('login') }}" class="btn-primary inline-flex">Back to login</a>
    </div>
</div>
@endsection
