{{-- 503 Maintenance — design/503_maintenance_mode_standardized --}}
@extends('layouts.guest')
@section('title', '503 Maintenance')
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <img class="mx-auto mb-6 w-64 h-auto" src="{{ brand_logo_url('full') }}" alt="">
        <p class="font-label-caps text-primary mb-2">Error 503</p>
        <h1 class="font-headline-sm text-headline-sm">Under maintenance</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">Aanigemas is briefly unavailable while we improve the system. Please check back soon.</p>
        <a href="{{ url('/') }}" class="btn-primary inline-flex">Retry</a>
    </div>
</div>
@endsection
