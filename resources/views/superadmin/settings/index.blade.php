{{--
    SuperAdmin global settings.

    One card per SettingGroup, each with its own save action so a mistake
    in Tax cannot rewrite Locale. Layout follows design/global_settings
    (dense row, compact toggle) with the spec's per-group save.
--}}
@extends('layouts.superadmin')

@section('title', 'Global Settings')
@section('page-title', 'Global Settings')

@section('content')
<x-page-header
    title="Global Settings"
    subtitle="Business rules that apply to every branch. Changing a toggle stops new generation only — existing codes and links keep working."
>
    <x-slot:actions>
        <x-btn href="{{ route('superadmin.settings.history') }}" variant="secondary">
            Change history
        </x-btn>
    </x-slot:actions>
</x-page-header>

@if($errors->has('settings'))
    <x-alert type="error" class="settings-banner">{{ $errors->first('settings') }}</x-alert>
@endif

<div class="settings-stack">
    @foreach($groups as $card)
        <x-settings.group-card
            :group="$card['group']"
            :definitions="$card['definitions']"
            :values="$card['values']"
            :hints="$card['hints'] ?? []"
            :editable="$editable"
            :action="route('superadmin.settings.update', $card['group']->value)"
            :logo-url="$logoUrl"
            :favicon-url="$faviconUrl"
        />
    @endforeach
</div>
@endsection
