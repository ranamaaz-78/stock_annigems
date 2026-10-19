{{-- Empty state block (SCSS: .empty-state). --}}
@props([
    'title' => null,
    'message' => null,
    'icon' => 'inbox',
])

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    <span class="material-symbols-outlined icon icon--lg empty-state__icon">{{ $icon }}</span>
    <h3 class="empty-state__title">{{ $title ?? __('No records found') }}</h3>
    <p class="empty-state__message">{{ $message ?? __('There is nothing to show here yet.') }}</p>
    @isset($actions)
        <div class="empty-state__actions">{{ $actions }}</div>
    @endisset
</div>
