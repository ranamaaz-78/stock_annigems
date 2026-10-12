{{-- Edit an existing global customer. --}}
@extends('layouts.branch')

@section('title', 'Edit Customer')
@section('page-title', 'Edit Customer')

@section('content')
<x-page-header title="Edit {{ $customer->name }}" :subtitle="$customer->customer_code">
    <x-slot:actions>
        <x-btn href="{{ route('branch.customers.show', $customer) }}" variant="secondary">Back</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel">
    <div class="panel__body">
        <form method="POST" action="{{ route('branch.customers.update', $customer) }}" data-validate-form data-loading-form>
            @csrf
            @method('PUT')
            @include('branch.customers._form', ['customer' => $customer])

            <div class="form-actions">
                <x-btn href="{{ route('branch.customers.show', $customer) }}" variant="secondary">Cancel</x-btn>
                <x-btn type="submit" loading-text="Saving...">Update customer</x-btn>
            </div>
        </form>
    </div>
</div>
@endsection
