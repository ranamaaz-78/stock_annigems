{{--
    Branch stone list.

    Three scopes, always shown, so a filter never silently changes what is listed:
      Folders   one tile per material; only a search by material name applies
      All lots  every lot of the branch, with every lot filter and a folder picker
      Folder    the lots of one material (opened from a tile), with every lot filter

    Reset always returns to the scope the operator is in.
--}}
@extends('layouts.branch')

@section('title', $category?->name ?? __('Stones'))
@section('page-title', __('Stones'))

@php
    $inLots = $mode !== 'folders';
    // Where "Reset" and the filter form send the operator: back to the same scope.
    $scopeQuery = match ($mode) {
        'folder' => ['category' => $category->uuid],
        'lots' => ['view' => 'lots'],
        default => [],
    };
    $resetUrl = route('branch.stones.index', $scopeQuery);
    $title = match ($mode) {
        'folder' => $category->name,
        'lots' => __('All lots'),
        default => __('Stones'),
    };
    $subtitle = match ($mode) {
        'folder' => __('Every lot of this material.'),
        'lots' => __('Every lot in the branch, across all materials.'),
        default => __('One folder per material. Open one to see its lots.'),
    };
@endphp

@section('content')
<x-page-header :title="$title" :subtitle="$subtitle">
    <x-slot:actions>
        @if($mode === 'folder')
            @can('manage', $category)
                <x-btn variant="secondary" data-modal-open="rename-folder-modal">
                    <span class="material-symbols-outlined icon icon--sm">edit</span>
                    {{ __('Rename folder') }}
                </x-btn>
                <form method="POST" action="{{ route('branch.stone-folders.destroy', $category) }}" data-loading-form data-confirm-delete-folder
                      data-folder-name="{{ $category->name }}" data-lot-count="{{ $category->stones()->count() }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <x-btn type="submit" variant="danger" :loading-text="__('Deleting...')">{{ __('Delete folder') }}</x-btn>
                </form>
            @endcan
        @endif
        @if(auth()->user()->hasPermission('stones.intake'))
            <x-btn href="{{ route('branch.stone-intakes.scan') }}" variant="secondary">{{ __('Stone Intake') }}</x-btn>
        @endif
        @can('create', \App\Models\Stone::class)
            <x-btn href="{{ route('branch.stone-imports.index') }}" variant="secondary">{{ __('Import History') }}</x-btn>
            <button type="button" class="btn btn--excel" data-import-open>
                <span class="material-symbols-outlined icon icon--sm">table_view</span>
                {{ __('Upload Excel') }}
            </button>
            <x-btn href="{{ route('branch.stones.create') }}">{{ __('Register Stone') }}</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

{{-- Where you are: Folders, All lots, or one folder (with a way back). --}}
<nav class="stone-scope" aria-label="{{ __('Stone view') }}">
    <a href="{{ route('branch.stones.index') }}" @class(['stone-scope__tab', 'is-on' => $mode === 'folders'])>
        <span class="material-symbols-outlined icon icon--sm">folder</span> {{ __('Folders') }}
    </a>
    <a href="{{ route('branch.stones.index', ['view' => 'lots']) }}" @class(['stone-scope__tab', 'is-on' => $mode === 'lots'])>
        <span class="material-symbols-outlined icon icon--sm">list</span> {{ __('All lots') }}
    </a>
    @if($mode === 'folder')
        <span class="stone-scope__crumb" aria-current="page">
            <span class="material-symbols-outlined icon icon--sm">chevron_right</span>
            <span class="material-symbols-outlined icon icon--sm">folder_open</span>
            {{ $category->name }}
        </span>
    @endif
</nav>

<form method="GET" action="{{ route('branch.stones.index') }}" class="filter-bar">
    {{-- The form keeps the scope: the folder being viewed, or the all-lots view. --}}
    @foreach($scopeQuery as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach

    <x-filter.search
        name="q"
        :value="$filters['q'] ?? ''"
        :placeholder="$inLots ? __('Code, barcode, name, tray, size or remarks…') : __('Search materials…')"
        aria-label="{{ $inLots ? __('Search lots') : __('Search materials') }}"
    />

    @if($inLots)
        @if($mode === 'lots')
            <select name="category" class="input-field" aria-label="{{ __('Folder') }}">
                <option value="">{{ __('All folders') }}</option>
                @foreach($folderOptions as $folder)
                    <option value="{{ $folder->uuid }}" @selected(($filters['category'] ?? '') === $folder->uuid)>{{ $folder->name }}</option>
                @endforeach
            </select>
        @endif
        @if(count($pricingModes) > 1 || ! empty($filters['pricing_mode']))
        <select name="pricing_mode" class="input-field" aria-label="{{ __('Pricing mode') }}">
            <option value="">{{ __('All pricing') }}</option>
            @foreach($pricingModes as $value => $label)
                <option value="{{ $value }}" @selected(($filters['pricing_mode'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @endif
        @if(count($shapes) > 0)
        <select name="shape" class="input-field" aria-label="{{ __('Shape') }}">
            <option value="">{{ __('All shapes') }}</option>
            @foreach($shapes as $shape)
                <option value="{{ $shape }}" @selected(($filters['shape'] ?? '') === $shape)>{{ $shape }}</option>
            @endforeach
        </select>
        @endif
        @if(count($statuses) > 1 || ! empty($filters['status']))
        <select name="status" class="input-field" aria-label="{{ __('Lot status') }}">
            <option value="">{{ __('Any lot status') }}</option>
            @foreach($statuses as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @endif
        @if(count($trays) > 0)
        <select name="tray_no" class="input-field" aria-label="{{ __('Tray') }}">
            <option value="">{{ __('All trays') }}</option>
            @foreach($trays as $tray)
                <option value="{{ $tray }}" @selected(($filters['tray_no'] ?? '') === $tray)>{{ $tray }}</option>
            @endforeach
        </select>
        @endif
        <input type="number" name="carat" value="{{ $filters['carat'] ?? '' }}" min="0" step="any"
               class="input-field" placeholder="{{ __('Carat, e.g. 1.1') }}" aria-label="{{ __('Carat (ct)') }}">
        <select name="stock" class="input-field" aria-label="{{ __('Stock') }}">
            <option value="">{{ __('All stock') }}</option>
            <option value="in" @selected(($filters['stock'] ?? '') === 'in')>{{ __('In stock') }}</option>
            <option value="out" @selected(($filters['stock'] ?? '') === 'out')>{{ __('Out of stock') }}</option>
        </select>
        <select name="is_active" class="input-field" aria-label="{{ __('Status') }}">
            <option value="">{{ __('Any status') }}</option>
            <option value="1" @selected(($filters['is_active'] ?? '') === '1')>{{ __('Active') }}</option>
            <option value="0" @selected(($filters['is_active'] ?? '') === '0')>{{ __('Inactive') }}</option>
        </select>
    @endif

    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ $inLots ? __('Filter') : __('Search') }}</x-btn>
        <a href="{{ $resetUrl }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>


@if($stones !== null)
    @include('branch.stones._lots')
@else
    @include('branch.stones._folders')
@endif


@can('create', \App\Models\Stone::class)
    @include('branch.stones._import-modal')
@endcan

@include('branch.stones._label-modal')

@if($mode === 'folder')
    @can('manage', $category)
        @include('branch.stones._folder-rename-modal')
    @endcan
@endif
@endsection
