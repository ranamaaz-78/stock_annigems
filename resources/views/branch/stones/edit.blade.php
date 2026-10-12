{{-- Edit one lot. Changing the piece count writes a stock movement. --}}
@extends('layouts.branch')

@section('title', 'Edit '.$stone->stone_code)
@section('page-title', 'Edit '.$stone->stone_code)

@section('content')
<x-page-header title="Edit {{ $stone->stone_code }}" subtitle="On-hand is {{ $stone->quantity }} pcs. Changing the piece count records a stock movement.">
    <x-slot:actions>
        <x-btn href="{{ route('branch.stones.show', $stone) }}" variant="secondary">Back</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
    <form method="POST" action="{{ route('branch.stones.update', $stone) }}" data-validate-form data-loading-form>
        @csrf
        @method('PUT')
        @include('branch.stones._form', ['stone' => $stone])
        <div class="flex justify-end gap-2 pt-4 border-t border-outline-variant mt-4">
            <x-btn href="{{ route('branch.stones.show', $stone) }}" variant="secondary">Cancel</x-btn>
            <x-btn type="submit" loading-text="Saving...">Save</x-btn>
        </div>
    </form>
</div>
@endsection
