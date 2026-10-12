{{-- 503 Maintenance — design/503_maintenance_mode_standardized --}}
@extends('layouts.guest')
@section('title', '503 Maintenance')
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <svg class="mx-auto mb-6 w-40 h-40" viewBox="0 0 160 160" fill="none" aria-hidden="true">
            <circle cx="80" cy="80" r="48" fill="#e6eff8" stroke="#d3c4af" stroke-width="2"/>
            <path d="M55 95 L70 55 L90 85 L105 55" stroke="#785600" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="80" cy="110" r="6" fill="#986d00"/>
        </svg>
        <p class="font-label-caps text-primary mb-2">Error 503</p>
        <h1 class="font-headline-sm text-headline-sm">Under maintenance</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">Annigems is briefly unavailable while we improve the system. Please check back soon.</p>
        <a href="{{ url('/') }}" class="btn-primary inline-flex">Retry</a>
    </div>
</div>
@endsection
