{{-- Select dropdown with label and inline error (SCSS design system).
     Native <select class="input-field"> is upgraded to Select2 search by
     resources/js/modules/select-search.js. Opt out with data-no-select2. --}}
@props([
    'name',
    'label',
    'options' => [],
    'value' => null,
    'required' => false,
    'placeholder' => 'Select…',
])

@php
    $fieldValue = old($name, $value);
    $hasError = $errors->has($name);
@endphp

<div data-field class="form-field">
    <label for="{{ $name }}" class="form-label">
        {{ $label }}@if($required)<span class="form-label__required"> *</span>@endif
    </label>
    <select
        id="{{ $name }}"
        name="{{ $name }}"
        @if($required) required @endif
        {{ $attributes->merge([
            'class' => 'input-field' . ($hasError ? ' is-invalid' : ''),
        ]) }}
    >
        <option value="">{{ $placeholder }}</option>
        @foreach($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $fieldValue === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    <p data-error-for="{{ $name }}" class="form-error {{ $hasError ? '' : 'is-hidden' }}">
        {{ $errors->first($name) }}
    </p>
</div>
