{{-- Register a new global customer. --}}
@extends('layouts.branch')

@section('title', __('Add Customer'))
@section('page-title', __('Add Customer'))

@section('content')
<x-page-header :title="__('Add Customer')" :subtitle="__('Customers are shared across all branches.')">
    <x-slot:actions>
        <x-btn href="{{ route('branch.customers.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel">
    <div class="panel__body">
        <form method="POST" action="{{ route('branch.customers.store') }}" data-validate-form data-loading-form>
            @csrf
            @include('branch.customers._form')

            <div class="form-actions">
                <x-btn href="{{ route('branch.customers.index') }}" variant="secondary">{{ __('Cancel') }}</x-btn>
                <x-btn type="submit" :loading-text="__('Saving...')">{{ __('Save customer') }}</x-btn>
            </div>
        </form>
    </div>
</div>
@endsection
