{{-- Edit an existing global customer. --}}
@extends('layouts.branch')

@section('title', __('Edit Customer'))
@section('page-title', __('Edit Customer'))

@section('content')
<x-page-header :title="__('Edit').' '.$customer->name" :subtitle="$customer->customer_code">
    <x-slot:actions>
        <x-btn href="{{ route('branch.customers.show', $customer) }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel">
    <div class="panel__body">
        <form method="POST" action="{{ route('branch.customers.update', $customer) }}" data-validate-form data-loading-form>
            @csrf
            @method('PUT')
            @include('branch.customers._form', ['customer' => $customer])

            <div class="form-actions">
                <x-btn href="{{ route('branch.customers.show', $customer) }}" variant="secondary">{{ __('Cancel') }}</x-btn>
                <x-btn type="submit" :loading-text="__('Saving...')">{{ __('Update customer') }}</x-btn>
            </div>
        </form>
    </div>
</div>
@endsection
