{{--
    Dialog with an icon header, scrolling body and a pinned footer.

    Same open/close contract as <x-modal> (root id, `hidden` class while
    closed, [data-modal-close] / [data-modal-overlay]), so modal.js and the
    page scripts drive it unchanged. The slot owns the body and footer: put
    `.dialog__body` and `.dialog__footer` inside it (typically inside a
    <form>, so the footer's submit button belongs to that form).

    size: md (default) | lg | xl
--}}
@props([
    'id',
    'title',
    'subtitle' => null,
    'icon' => null,
    'size' => 'md',
])

<div
    id="{{ $id }}"
    {{ $attributes->except('class') }}
    class="dialog hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
>
    <div class="dialog__overlay" data-modal-overlay></div>
    <div class="dialog__panel dialog__panel--{{ $size }}">
        <header class="dialog__header">
            @if($icon)
                <span class="dialog__icon" aria-hidden="true">
                    <span class="material-symbols-outlined icon icon--md">{{ $icon }}</span>
                </span>
            @endif
            <div class="dialog__heading">
                <h2 id="{{ $id }}-title" class="dialog__title">{{ $title }}</h2>
                @if($subtitle)
                    <p class="dialog__subtitle">{{ $subtitle }}</p>
                @endif
            </div>
            <button type="button" class="dialog__close" data-modal-close aria-label="{{ __('Close') }}">
                <span class="material-symbols-outlined icon icon--sm">close</span>
            </button>
        </header>
        {{ $slot }}
    </div>
</div>
