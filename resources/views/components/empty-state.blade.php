{{-- Empty state block (SCSS: .empty-state). --}}
@props([
    'title' => 'No records found',
    'message' => 'There is nothing to show here yet.',
    'icon' => 'inbox',
])

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    <span class="material-symbols-outlined icon icon--lg empty-state__icon">{{ $icon }}</span>
    <h3 class="empty-state__title">{{ $title }}</h3>
    <p class="empty-state__message">{{ $message }}</p>
    @isset($actions)
        <div class="empty-state__actions">{{ $actions }}</div>
    @endisset
</div>
