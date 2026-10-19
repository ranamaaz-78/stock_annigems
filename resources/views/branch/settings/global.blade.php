{{--
    Admin read-only mirror of SuperAdmin global settings (spec 6.4).

    Same cards as the SuperAdmin screen, no save buttons, plus the current
    gold rates so an Admin can see why a quotation priced the way it did.
--}}
@extends('layouts.branch')

@section('title', __('Company Settings'))
@section('page-title', __('Company Settings'))

@section('content')
<x-page-header
    :title="__('Company settings')"
    :subtitle="__('Read-only. These rules apply to every branch.')"
>
    <x-slot:actions>
        <x-btn href="{{ route('branch.settings.index') }}" variant="secondary">
            {{ __('Branch settings') }}
        </x-btn>
    </x-slot:actions>
</x-page-header>

<x-alert type="info" class="settings-banner">
    {{ __('These settings are managed by the SuperAdmin and cannot be changed from this branch.') }}
</x-alert>

<div class="settings-stack">
    <section class="panel settings-card">
        <div class="panel__header settings-card__header">
            <h2 class="panel__title text-headline-sm">
                <span class="material-symbols-outlined icon icon--sm settings-card__icon" aria-hidden="true">brightness_high</span>
                {{ __('Current gold rates') }}
            </h2>
        </div>
        <div class="settings-rates">
            @forelse($currentRates as $row)
                <div class="settings-rate">
                    <span class="settings-rate__name">{{ $row['purity']->metalType?->name }} — {{ $row['purity']->name }}</span>
                    <span class="settings-rate__value">
                        @if($row['rate'])
                            {{ setting_money($row['rate']->rate_per_gram) }}
                        @else
                            {{ __('Not set') }}
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-body-sm">{{ __('No active purities.') }}</p>
            @endforelse
        </div>
    </section>

    @foreach($groups as $card)
        <x-settings.group-card
            :group="$card['group']"
            :definitions="$card['definitions']"
            :values="$card['values']"
            :hints="$card['hints'] ?? []"
            :editable="false"
        />
    @endforeach
</div>
@endsection
