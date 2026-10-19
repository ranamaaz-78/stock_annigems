{{--
    Branch settings — Admin writable, Manager read-only.

    Contact details edit the Branch record (the name printed on invoices).
    Print preferences and footer notes live in branch_settings. A live
    preview updates when label size or invoice format changes.
--}}
@extends('layouts.branch')

@section('title', __('Settings'))
@section('page-title', __('Settings'))

@section('content')
<x-page-header
    :title="__('Branch settings')"
    :subtitle="__('Contact details, labels and the notes printed on this branch\'s documents.')"
>
    @if(auth()->user()?->can('viewGlobalSettings'))
        <x-slot:actions>
            <x-btn href="{{ route('branch.company-settings.show') }}" variant="secondary">
                {{ __('Company settings') }}
            </x-btn>
        </x-slot:actions>
    @endif
</x-page-header>

@unless($editable)
    <x-alert type="info" class="settings-banner">
        {{ __('You can view these preferences. Only a branch Admin can change them.') }}
    </x-alert>
@endunless

<div class="settings-stack">
    {{-- Contact posts to BranchService, never to branch_settings. --}}
    <section class="panel settings-card">
        <div class="panel__header settings-card__header">
            <h2 class="panel__title text-headline-sm">
                <span class="material-symbols-outlined icon icon--sm settings-card__icon" aria-hidden="true">store</span>
                {{ __('Branch contact') }}
            </h2>
        </div>

        @if($editable)
            <form method="POST" action="{{ route('branch.settings.contact') }}" data-settings-form data-group-label="{{ __('Branch contact') }}">
                @csrf
                @method('PUT')
                <div class="panel--padded">
                    <div class="form-grid form-grid--2">
                        <x-form.input name="name" :label="__('Display name')" :value="$branch->name" required />
                        <x-form.input name="city" :label="__('City')" :value="$branch->city" required />
                        <x-form.input name="contact_number" :label="__('Phone')" :value="$branch->contact_number" required />
                        <x-form.input name="email" type="email" :label="__('Email')" :value="$branch->email" />
                    </div>
                    <x-form.textarea name="address" :label="__('Address')" :value="$branch->address" required />
                </div>
                <div class="settings-card__footer">
                    <x-btn type="button" data-settings-save :loading-text="__('Saving...')">{{ __('Save Branch Contact') }}</x-btn>
                </div>
            </form>
        @else
            <div class="panel--padded">
                <dl class="detail-list">
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Display name') }}</dt>
                        <dd class="detail-list__value">{{ $branch->name }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('City') }}</dt>
                        <dd class="detail-list__value">{{ $branch->city }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Phone') }}</dt>
                        <dd class="detail-list__value">{{ $branch->contact_number }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Email') }}</dt>
                        <dd class="detail-list__value">{{ $branch->email ?: '—' }}</dd>
                    </div>
                    <div class="detail-list__item detail-list__item--full">
                        <dt class="detail-list__term">{{ __('Address') }}</dt>
                        <dd class="detail-list__value">{{ $branch->address }}</dd>
                    </div>
                </dl>
            </div>
        @endif
    </section>

    <x-settings.group-card
        :group="\App\Enums\SettingGroup::PRINT"
        :definitions="$printDefinitions"
        :values="$print"
        :editable="$editable"
        :action="$editable ? route('branch.settings.update', 'print') : null"
    />

    <x-settings.group-card
        :group="\App\Enums\SettingGroup::INVOICE"
        :definitions="$footerDefinitions"
        :values="$footers"
        :editable="$editable"
        :action="$editable ? route('branch.settings.update', 'invoice') : null"
    />

    <section class="panel settings-card">
        <div class="panel__header settings-card__header">
            <h2 class="panel__title text-headline-sm">
                <span class="material-symbols-outlined icon icon--sm settings-card__icon" aria-hidden="true">preview</span>
                {{ __('Print preview') }}
            </h2>
        </div>
        <div class="settings-preview" data-print-preview>
            <div class="settings-preview__pane">
                <p class="settings-preview__caption">{{ __('Label') }}</p>
                <div class="settings-preview__label settings-preview__label--{{ $print['label_size'] }}" data-preview-label>
                    <span class="settings-preview__code">{{ __('JWL-000001') }}</span>
                    <span class="settings-preview__meta">22K · 4.250 g</span>
                </div>
            </div>
            <div class="settings-preview__pane">
                <p class="settings-preview__caption">{{ __('Invoice') }}</p>
                <div class="sheet settings-preview__sheet settings-preview__sheet--{{ $print['invoice_print_format'] }}" data-preview-sheet>
                    <span class="settings-preview__code">{{ setting('company_name') }}</span>
                    <span class="settings-preview__meta">{{ $branch->name }}</span>
                    <span class="settings-preview__meta">{{ __('INV-000001') }}</span>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection
