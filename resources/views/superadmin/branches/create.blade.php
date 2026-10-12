{{-- Create branch page — dense multi-column form. --}}
@extends('layouts.superadmin')

@section('title', 'Add Branch')
@section('page-title', 'Add Branch')

@section('content')
<x-page-header title="Add Branch" subtitle="Register a new location in the system." />

<div class="max-w-3xl bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
    <form method="POST" action="{{ route('superadmin.branches.store') }}" data-validate-form data-loading-form class="space-y-4">
        @csrf
        @include('superadmin.branches._form')
        <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <x-btn href="{{ route('superadmin.branches.index') }}" variant="secondary">Cancel</x-btn>
            <x-btn type="submit" loading-text="Saving...">Save Branch</x-btn>
        </div>
    </form>
</div>
@endsection
