{{-- Page header with title, optional subtitle, and actions slot. --}}
@props([
    'title',
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'page-header']) }}>
    <div>
        <h1 class="page-header__title text-headline-sm">{{ $title }}</h1>
        @if($subtitle)
            <p class="page-header__subtitle text-body-sm">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="page-header__actions">
            {{ $actions }}
        </div>
    @endisset
</div>
