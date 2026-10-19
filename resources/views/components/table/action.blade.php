{{-- Single icon action (view, print, edit) inside a list row. --}}
@props([
    'href',
    'icon' => 'visibility',
    'label' => null,
])

@php($label ??= __('View'))
<a
    href="{{ $href }}"
    class="table-admin__action"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    {{ $attributes }}
>
    <span class="material-symbols-outlined icon icon--sm">{{ $icon }}</span>
</a>
