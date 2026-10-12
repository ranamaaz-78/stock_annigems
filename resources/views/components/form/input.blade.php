{{-- Form text/email/password/tel input with label and inline error (SCSS design system). --}}
@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'required' => false,
    'help' => null,
])

@php
    // Array-style names (rates[7]) must be read with dot notation for old()
    // and the error bag, otherwise repopulation and inline errors silently fail.
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $fieldValue = old($errorKey, $value);
    $hasError = $errors->has($errorKey);
    $fieldId = 'field-'.\Illuminate\Support\Str::slug(str_replace(['[', ']'], ['-', ''], $name));
@endphp

<div data-field class="form-field">
    @if($label !== null)
        <label for="{{ $fieldId }}" class="form-label">
            {{ $label }}@if($required)<span class="form-label__required"> *</span>@endif
        </label>
    @endif
    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ $type === 'password' ? '' : $fieldValue }}"
        @if($required) required @endif
        {{ $attributes->except('class')->merge([
            'class' => 'input-field' . ($hasError ? ' is-invalid' : ''),
        ]) }}
    >
    @if($help)
        <p class="form-help">{{ $help }}</p>
    @endif
    <p data-error-for="{{ $name }}" class="form-error {{ $hasError ? '' : 'is-hidden' }}">
        {{ $errors->first($name) }}
    </p>
</div>
