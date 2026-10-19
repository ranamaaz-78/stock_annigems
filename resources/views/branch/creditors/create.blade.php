{{-- Register a new branch creditor. --}}
@extends('layouts.branch')

@section('title', __('Add Creditor'))
@section('page-title', __('Add Creditor'))

@section('content')
<x-page-header :title="__('Add Creditor')" :subtitle="__('Register the party first, then issue a performa against them.')">
    <x-slot:actions>
        <x-btn href="{{ route('branch.creditors.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel">
    <div class="panel__body">
        <form method="POST" action="{{ route('branch.creditors.store') }}" data-validate-form data-loading-form>
            @csrf
            @include('branch.creditors._form')

            <div class="form-actions">
                <x-btn href="{{ route('branch.creditors.index') }}" variant="secondary">{{ __('Cancel') }}</x-btn>
                <x-btn type="submit" :loading-text="__('Saving...')">{{ __('Save creditor') }}</x-btn>
            </div>
        </form>
    </div>
</div>
@endsection
