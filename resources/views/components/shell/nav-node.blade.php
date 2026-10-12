{{-- Recursive sidebar node: module accordion or leaf link. --}}
@props([
    'item' => [],
    'depth' => 0,
])

@php
    $children = $item['children'] ?? [];
    $hasChildren = $children !== [];
    $enabled = $item['enabled'] ?? false;
    $active = $item['active'] ?? false;
    $label = $item['label'] ?? '';
    $icon = $item['icon'] ?? 'chevron_right';
    $groupId = $item['id'] ?? \Illuminate\Support\Str::slug($label);
    $linkClass = $depth > 0 ? 'sa-sidebar__sublink' : 'sa-sidebar__link';
@endphp

@if($hasChildren)
    <div
        class="sa-sidebar__group {{ $active ? 'is-open is-current' : '' }}"
        data-nav-group="{{ $groupId }}"
    >
        <button
            type="button"
            class="sa-sidebar__link sa-sidebar__group-toggle {{ $active ? 'is-current' : '' }}"
            data-nav-toggle
            aria-expanded="{{ $active ? 'true' : 'false' }}"
            aria-controls="nav-{{ $groupId }}"
            title="{{ $label }}"
        >
            <span class="material-symbols-outlined icon icon--md">{{ $icon }}</span>
            <span class="sa-sidebar__label">{{ $label }}</span>
            <span class="material-symbols-outlined icon icon--xs sa-sidebar__chevron">expand_more</span>
        </button>
        <div class="sa-sidebar__children" id="nav-{{ $groupId }}">
            <div class="sa-sidebar__children-inner">
                @foreach($children as $child)
                    <x-shell.nav-node :item="$child" :depth="$depth + 1" />
                @endforeach
            </div>
        </div>
    </div>
@elseif($enabled && ! empty($item['route']))
    <a
        href="{{ route($item['route']) }}"
        class="{{ $linkClass }} {{ $active ? 'is-active' : '' }}"
        title="{{ $label }}"
    >
        <span class="material-symbols-outlined icon icon--md">{{ $icon }}</span>
        <span class="sa-sidebar__label">{{ $label }}</span>
    </a>
@else
    <span class="{{ $linkClass }} sa-sidebar__link-disabled" title="{{ $item['stub'] ?? 'Coming in a later module' }}">
        <span class="material-symbols-outlined icon icon--md">{{ $icon }}</span>
        <span class="sa-sidebar__label">{{ $label }}</span>
    </span>
@endif
