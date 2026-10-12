{{-- 429 Too Many Requests — design/429_too_many_requests_standardized --}}
@extends('layouts.guest')
@section('title', '429 Too Many Requests')
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-background">
    <div class="max-w-md w-full text-center">
        <svg class="mx-auto mb-6 w-40 h-40" viewBox="0 0 160 160" fill="none" aria-hidden="true">
            <rect x="35" y="50" width="90" height="60" rx="8" fill="#fef3c7" stroke="#b45309" stroke-width="2"/>
            <path d="M55 80 H105" stroke="#b45309" stroke-width="4" stroke-linecap="round"/>
            <circle cx="65" cy="80" r="6" fill="#785600"/>
            <circle cx="95" cy="80" r="6" fill="#785600"/>
            <path d="M80 35 L88 55 H72 Z" fill="#785600"/>
        </svg>
        <p class="font-label-caps text-primary mb-2">Error 429</p>
        <h1 class="font-headline-sm text-headline-sm">Too many requests</h1>
        <p class="text-body-sm text-on-surface-variant mt-2 mb-6">You have made too many attempts. Please wait a moment and try again.</p>
        <a href="{{ route('login') }}" class="btn-primary inline-flex">Back to login</a>
    </div>
</div>
@endsection
