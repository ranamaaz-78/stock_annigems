{{-- Edit branch page. --}}
@extends('layouts.superadmin')

@section('title', 'Edit Branch')
@section('page-title', 'Edit Branch')

@section('content')
<x-page-header title="Edit Branch" :subtitle="$branch->name" />

<div class="max-w-3xl bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
    <form method="POST" action="{{ route('superadmin.branches.update', $branch) }}" data-validate-form data-loading-form class="space-y-4">
        @csrf
        @method('PUT')
        @include('superadmin.branches._form', ['branch' => $branch])
        <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <x-btn href="{{ route('superadmin.branches.show', $branch) }}" variant="secondary">Cancel</x-btn>
            <x-btn type="submit" loading-text="Saving...">Update Branch</x-btn>
        </div>
    </form>
</div>
@endsection
