{{--
    Dense settings row: label + helper | control. Children of a parent
    toggle (tax rate under tax_enabled, etc.) stay hidden until the parent
    is on. Feature rows carry the generation-only note from the spec.
--}}
@props([
    'definition',
    'value' => null,
    'hint' => null,
    'editable' => true,
    'logoUrl' => null,
    'faviconUrl' => null,
])

@php
    $key = $definition['key'];
    $widget = $definition['widget'];
    $parent = $definition['parent'] ?? null;
    $label = \Illuminate\Support\Str::headline($key);
    $errorKey = $key;
    $hasError = $errors->has($errorKey);
    $fieldId = 'setting-'.$key;
    $display = old($key, $value);

    if ($widget === 'json_list' && is_array($display)) {
        $display = implode(', ', $display);
    }

    $isFile = $widget === 'file';
    $previewUrl = $key === 'company_logo' ? $logoUrl : ($key === 'company_favicon' ? $faviconUrl : null);
@endphp

<div
    class="settings-row {{ $parent ? 'settings-row--child' : '' }}"
    @if($parent) data-settings-parent="{{ $parent }}" @endif
>
    <div class="settings-row__copy">
        <p class="settings-row__label">
            <label for="{{ $fieldId }}">{{ $label }}</label>
        </p>
        <p class="settings-row__help">{{ $definition['description'] }}</p>
        @if(is_array($hint) && isset($hint['sample']))
            <p class="settings-row__help">
                @if(str_ends_with($key, '_start'))
                    Highest issued: {{ $hint['highest'] }}. Next code: {{ $hint['sample'] }}.
                @else
                    Next new code: {{ $hint['sample'] }}.
                @endif
            </p>
        @endif
    </div>

    <div>
        @if(! empty($definition['feature_note']))
            <p class="settings-row__note">Disabling this stops new generation only. Existing codes and links will keep working.</p>
        @endif
        <p data-error-for="{{ $key }}" class="form-error {{ $hasError ? '' : 'is-hidden' }}">
            {{ $errors->first($errorKey) }}
        </p>
    </div>

    <div class="settings-row__control">
        @if(! $editable)
            @if($widget === 'toggle')
                <x-settings.toggle :name="$key" :checked="(bool) $display" disabled />
            @elseif($isFile)
                @if($previewUrl)
                    <img class="settings-row__preview-img" src="{{ $previewUrl }}" alt="{{ $label }}">
                @else
                    <span class="settings-readonly">Not set</span>
                @endif
            @else
                <span class="settings-readonly">{{ $display === null || $display === '' ? '—' : $display }}</span>
            @endif
        @elseif($widget === 'toggle')
            <x-settings.toggle :name="$key" :checked="(bool) $display" />
        @elseif($widget === 'select')
            <select id="{{ $fieldId }}" name="{{ $key }}" class="input-field {{ $hasError ? 'is-invalid' : '' }}">
                @foreach($definition['options'] as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $display === (string) $optionValue)>{{ $optionLabel }}</option>
                @endforeach
            </select>
        @elseif($widget === 'textarea')
            <textarea id="{{ $fieldId }}" name="{{ $key }}" rows="3" class="input-field {{ $hasError ? 'is-invalid' : '' }}">{{ $display }}</textarea>
        @elseif($widget === 'file')
            <div>
                @if($previewUrl)
                    <div class="settings-row__preview">
                        <img class="settings-row__preview-img" src="{{ $previewUrl }}" alt="Current {{ $label }}">
                    </div>
                @endif
                <input id="{{ $fieldId }}" name="{{ $key }}" type="file" accept="image/png,image/jpeg,image/webp,image/x-icon,.ico" class="input-field {{ $hasError ? 'is-invalid' : '' }}">
            </div>
        @elseif($widget === 'json_list')
            <input id="{{ $fieldId }}" name="{{ $key }}" type="text" value="{{ $display }}" class="input-field {{ $hasError ? 'is-invalid' : '' }}" placeholder="30, 60, 90, 180">
        @elseif($widget === 'timezone')
            <input id="{{ $fieldId }}" name="{{ $key }}" type="text" value="{{ $display }}" class="input-field {{ $hasError ? 'is-invalid' : '' }}" placeholder="Asia/Karachi">
        @elseif($widget === 'number')
            <input
                id="{{ $fieldId }}"
                name="{{ $key }}"
                type="number"
                value="{{ $display }}"
                step="any"
                @if(is_array($hint) && isset($hint['min']) && (int) $hint['min'] > 0)
                    min="{{ $hint['min'] }}"
                @endif
                class="input-field {{ $hasError ? 'is-invalid' : '' }}"
            >
        @else
            <input id="{{ $fieldId }}" name="{{ $key }}" type="text" value="{{ $display }}" class="input-field {{ $hasError ? 'is-invalid' : '' }}">
        @endif
    </div>
</div>
