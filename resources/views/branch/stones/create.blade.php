{{-- Register one stone lot. On-hand stock is the piece count entered here. --}}
@extends('layouts.branch')

@section('title', 'Register Stone')
@section('page-title', 'Register Stone')

@section('content')
<x-page-header
    title="Register Stone"
    subtitle="One row per lot, the same way the inventory sheet records it. A packet barcode is issued on save."
>
    <x-slot:actions>
        <x-btn href="{{ route('branch.stones.index') }}" variant="secondary">Back</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
    <form method="POST" action="{{ route('branch.stones.store') }}" data-validate-form data-loading-form>
        @csrf
        @include('branch.stones._form')
        <div class="flex justify-end gap-2 pt-4 border-t border-outline-variant mt-4">
            <x-btn href="{{ route('branch.stones.index') }}" variant="secondary">Cancel</x-btn>
            <x-btn type="submit" loading-text="Saving...">Register</x-btn>
        </div>
    </form>
</div>
@endsection
