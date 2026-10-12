{{-- Alert / banner (SCSS: .alert / .alert--*). --}}
@props([
    'type' => 'info',
])

@php
    $modifier = match ($type) {
        'success' => 'alert--success',
        'error' => 'alert--error',
        'warning' => 'alert--warning',
        default => 'alert--info',
    };
@endphp

<div {{ $attributes->merge(['class' => "alert $modifier"]) }} role="alert">
    {{ $slot }}
</div>
