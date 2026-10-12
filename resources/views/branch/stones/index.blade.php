{{--
    Branch stone list.

    Two states in one screen: the material folders, and the lots inside one
    folder. The folders are the default because a branch carries the same
    material across dozens of lots.
--}}
@extends('layouts.branch')

@section('title', $category?->name ?? 'Stones')
@section('page-title', 'Stones')

@section('content')
<x-page-header
    :title="$category?->name ?? 'Stones'"
    :subtitle="$category ? 'Every lot of this material.' : 'One folder per material. Open one to see its lots.'"
>
    <x-slot:actions>
        @if($category || $stones)
            <a href="{{ route('branch.stones.index') }}" class="btn btn--secondary">
                <span class="material-symbols-outlined icon icon--sm">arrow_back</span>
                All folders
            </a>
        @endif
        @if(auth()->user()->hasPermission('stones.intake'))
            <x-btn href="{{ route('branch.stone-intakes.scan') }}" variant="secondary">Stone Intake</x-btn>
        @endif
        @can('create', \App\Models\Stone::class)
            <x-btn href="{{ route('branch.stone-imports.index') }}" variant="secondary">Import History</x-btn>
            <button type="button" class="btn btn--excel" data-import-open>
                <span class="material-symbols-outlined icon icon--sm">table_view</span>
                Upload Excel
            </button>
            <x-btn href="{{ route('branch.stones.create') }}">Register Stone</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    {{-- Keep the open folder while filtering inside it. --}}
    @if($category)
        <input type="hidden" name="category" value="{{ $category->uuid }}">
    @endif
    <x-filter.search
        name="q"
        :value="$filters['q'] ?? ''"
        :placeholder="$category || $stones ? 'Code, barcode, name, tray, or sizeâ€¦' : 'Search materialsâ€¦'"
        aria-label="Search stones"
    />
    <select name="pricing_mode" class="input-field" aria-label="Pricing mode">
        <option value="">All pricing</option>
        @foreach($pricingModes as $value => $label)
            <option value="{{ $value }}" @selected(($filters['pricing_mode'] ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="status" class="input-field" aria-label="Lot status">
        <option value="">Any lot status</option>
        @foreach($statuses as $value => $label)
            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="tray_no" class="input-field" aria-label="Tray">
        <option value="">All trays</option>
        @foreach($trays as $tray)
            <option value="{{ $tray }}" @selected(($filters['tray_no'] ?? '') === $tray)>{{ $tray }}</option>
        @endforeach
    </select>
    <select name="stock" class="input-field" aria-label="Stock">
        <option value="">All stock</option>
        <option value="in" @selected(($filters['stock'] ?? '') === 'in')>In stock</option>
        <option value="out" @selected(($filters['stock'] ?? '') === 'out')>Out of stock</option>
    </select>
    <select name="is_active" class="input-field" aria-label="Status">
        <option value="">Any status</option>
        <option value="1" @selected(($filters['is_active'] ?? '') === '1')>Active</option>
        <option value="0" @selected(($filters['is_active'] ?? '') === '0')>Inactive</option>
    </select>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">Filter</x-btn>
        <a href="{{ route('branch.stones.index') }}" class="btn btn--secondary">Reset</a>
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
@endsection
