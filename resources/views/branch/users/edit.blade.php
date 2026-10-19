{{-- Edit branch staff user with permission grid. --}}
@extends('layouts.branch')

@section('title', __('Edit User'))
@section('page-title', __('Edit User'))

@section('content')
<x-page-header :title="__('Edit').' '.$user->name" :subtitle="$user->email">
    <x-slot:actions>
        <x-btn href="{{ route('branch.users.show', $user) }}" variant="secondary">{{ __('Cancel') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="max-w-3xl bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
    <form method="POST" action="{{ route('branch.users.update', $user) }}" data-validate-form data-loading-form class="space-y-4">
        @csrf
        @method('PUT')
        @include('branch.users._form')
        <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <x-btn type="submit" :loading-text="__('Saving...')">{{ __('Save Changes') }}</x-btn>
        </div>
    </form>
</div>
@endsection
