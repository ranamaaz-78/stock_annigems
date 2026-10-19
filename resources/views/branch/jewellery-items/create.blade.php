{{-- Create jewellery piece. Codes, QR, and derived weights are assigned on save. --}}
@extends('layouts.branch')

@section('title', __('Add item'))
@section('page-title', __('Add item'))

@section('content')
<x-page-header :title="__('Add item')" :subtitle="__('One physical piece. Pricing is not captured here.')">
    <x-slot:actions>
        <x-btn href="{{ route('branch.jewellery-items.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<form method="POST" action="{{ route('branch.jewellery-items.store') }}" enctype="multipart/form-data" data-validate-form data-loading-form data-jewellery-form>
    @csrf
    @include('branch.jewellery-items._form')
</form>
@endsection
