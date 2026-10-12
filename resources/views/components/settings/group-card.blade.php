{{--
    One settings group as its own form. SuperAdmin and the Admin read-only
    mirror share this card; $editable hides the save button so an Admin
    cannot POST a group they cannot write.
--}}
@props([
    'group',
    'definitions',
    'values',
    'hints' => [],
    'editable' => true,
    'action' => null,
    'logoUrl' => null,
    'faviconUrl' => null,
])

@php
    /** @var \App\Enums\SettingGroup $group */
    $enctype = collect($definitions)->contains(fn ($row) => ($row['widget'] ?? '') === 'file')
        ? 'multipart/form-data'
        : null;
@endphp

<section class="panel settings-card" data-settings-card="{{ $group->value }}">
    <div class="panel__header settings-card__header">
        <h2 class="panel__title text-headline-sm">
            <span class="material-symbols-outlined icon icon--sm settings-card__icon" aria-hidden="true">{{ $group->icon() }}</span>
            {{ $group->label() }}
        </h2>
    </div>

    @if($editable && $action)
        <form
            method="POST"
            action="{{ $action }}"
            @if($enctype) enctype="{{ $enctype }}" @endif
            data-settings-form
            data-group-label="{{ $group->label() }}"
            class="settings-card__form"
        >
            @csrf
            @method('PUT')
            <div class="settings-card__body">
                <x-settings.numbering-preview :hints="$hints" />
                @foreach($definitions as $definition)
                    <x-settings.row
                        :definition="$definition"
                        :value="$values[$definition['key']] ?? $definition['default']"
                        :hint="$hints[$definition['key']] ?? null"
                        :editable="true"
                        :logo-url="$logoUrl"
                        :favicon-url="$faviconUrl"
                    />
                @endforeach
            </div>
            <div class="settings-card__footer">
                <x-btn type="button" data-settings-save loading-text="Saving...">
                    Save {{ $group->label() }}
                </x-btn>
            </div>
        </form>
    @else
        <div class="settings-card__body">
            <x-settings.numbering-preview :hints="$hints" />
            @foreach($definitions as $definition)
                <x-settings.row
                    :definition="$definition"
                    :value="$values[$definition['key']] ?? $definition['default']"
                    :hint="$hints[$definition['key']] ?? null"
                    :editable="false"
                    :logo-url="$logoUrl"
                    :favicon-url="$faviconUrl"
                />
            @endforeach
        </div>
    @endif
</section>
