{{--
    Leading list cell: optional avatar/icon, primary title (often a link),
    and a muted meta line. Used on every operational table list.
--}}
@props([
    'href' => null,
    'title',
    'meta' => null,
    'mark' => null,
    'icon' => null,
    'imageUrl' => null,
    'imageAlt' => '',
])

@php
    $initials = null;
    if ($mark === true) {
        $parts = preg_split('/\s+/', trim((string) $title)) ?: [];
        $initials = strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
        $initials = $initials !== '' ? $initials : '—';
    } elseif (is_string($mark) && $mark !== '') {
        $initials = $mark;
    }
@endphp

<div {{ $attributes->class(['table-admin__identity']) }}>
    @if($imageUrl)
        {{-- A picture replaces the icon; clicking opens it full size in a new tab. --}}
        <a href="{{ $imageUrl }}" target="_blank" rel="noopener" class="table-admin__mark table-admin__mark--image" title="{{ __('Open image') }}">
            <img src="{{ $imageUrl }}" alt="{{ $imageAlt }}" loading="lazy">
        </a>
    @elseif($icon)
        <span class="table-admin__mark" aria-hidden="true">
            <span class="material-symbols-outlined icon icon--sm">{{ $icon }}</span>
        </span>
    @elseif($initials)
        <span class="table-admin__mark" aria-hidden="true">{{ $initials }}</span>
    @endif
    <div class="table-admin__copy">
        <div class="table-admin__title">
            @if($href)
                <a href="{{ $href }}" class="form-link">{{ $title }}</a>
            @else
                {{ $title }}
            @endif
        </div>
        @if(filled($meta))
            <span class="table-meta">{{ $meta }}</span>
        @endif
        {{ $slot }}
    </div>
</div>
