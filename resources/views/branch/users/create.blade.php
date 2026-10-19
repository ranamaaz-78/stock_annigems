{{-- Create branch staff user with permission grid. --}}
@extends('layouts.branch')

@section('title', __('Add User'))
@section('page-title', __('Add User'))

@section('content')
<x-page-header :title="__('Add User')" :subtitle="__('Staff accounts are optional. You can operate the entire branch alone as Admin.')" />

<div class="max-w-3xl bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
    <form method="POST" action="{{ route('branch.users.store') }}" data-validate-form data-loading-form class="space-y-4" id="staff-user-form">
        @csrf
        @include('branch.users._form')
        <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <x-btn href="{{ route('branch.users.index') }}" variant="secondary">{{ __('Cancel') }}</x-btn>
            <x-btn type="submit" :loading-text="__('Creating...')">{{ __('Create User') }}</x-btn>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
const form = document.getElementById('staff-user-form');
const manualFields = document.getElementById('manual-password-fields');
form?.querySelectorAll('input[name="password_mode"]').forEach((radio) => {
    radio.addEventListener('change', () => {
        if (!manualFields) return;
        manualFields.classList.toggle('hidden', radio.value !== 'manual');
    });
});
</script>
@endpush
