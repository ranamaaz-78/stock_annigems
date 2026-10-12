{{-- Textarea with label and inline error (SCSS design system). --}}
@props([
    'name',
    'label',
    'value' => null,
    'required' => false,
    'rows' => 3,
])

@php
    $fieldValue = old($name, $value);
    $hasError = $errors->has($name);
@endphp

<div data-field class="form-field">
    <label for="{{ $name }}" class="form-label">
        {{ $label }}@if($required)<span class="form-label__required"> *</span>@endif
    </label>
    <textarea
        id="{{ $name }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @if($required) required @endif
        {{ $attributes->merge([
            'class' => 'input-field' . ($hasError ? ' is-invalid' : ''),
        ]) }}
    >{{ $fieldValue }}</textarea>
    <p data-error-for="{{ $name }}" class="form-error {{ $hasError ? '' : 'is-hidden' }}">
        {{ $errors->first($name) }}
    </p>
</div>
