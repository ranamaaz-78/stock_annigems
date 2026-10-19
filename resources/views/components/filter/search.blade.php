{{-- Search field for a .filter-bar — leading search icon, wide column. --}}
@props([
    'name' => 'search',
    'value' => '',
    'placeholder' => null,
])

<div class="filter-bar__wide">
    <div class="input-with-icon">
        <span class="input-with-icon__icon">
            <span class="material-symbols-outlined icon icon--sm icon--secondary">search</span>
        </span>
        <input
            type="search"
            name="{{ $name }}"
            value="{{ $value }}"
            placeholder="{{ $placeholder ?? __('Search…') }}"
            class="input-with-icon__field"
            {{ $attributes }}
        >
    </div>
</div>
