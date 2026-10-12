{{-- Compact primary/secondary/danger button (SCSS: .btn--*). --}}
@props([
    'type' => 'button',
    'variant' => 'primary',
    'href' => null,
    'loadingText' => 'Please wait...',
    'block' => false,
    'disabled' => false,
])

@php
    $classes = match ($variant) {
        'secondary' => 'btn btn--secondary',
        'danger' => 'btn btn--danger',
        default => 'btn btn--primary',
    };
    if ($block) {
        $classes .= ' btn--block';
    }
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        data-loading-text="{{ $loadingText }}"
        @disabled($disabled)
        {{ $attributes->merge(['class' => $classes]) }}
    >
        {{ $slot }}
    </button>
@endif
