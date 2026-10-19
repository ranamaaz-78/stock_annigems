{{-- 403 Forbidden --}}
@extends('layouts.guest')
@section('title', __('403 Forbidden'))
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <svg class="mx-auto mb-6 w-40 h-40" viewBox="0 0 160 160" fill="none" aria-hidden="true">
            <circle cx="80" cy="80" r="50" fill="#ffdad6" stroke="#ba1a1a" stroke-width="2"/>
            <path d="M60 60 L100 100 M100 60 L60 100" stroke="#ba1a1a" stroke-width="6" stroke-linecap="round"/>
        </svg>
        <p class="font-label-caps text-primary mb-2">{{ __('Error 403') }}</p>
        <h1 class="font-headline-sm text-headline-sm">{{ __('Access denied') }}</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">{{ __('You do not have permission to view this page.') }}</p>
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('login') }}" class="btn-primary inline-flex">{{ __('Go back') }}</a>
    </div>
</div>
@endsection
