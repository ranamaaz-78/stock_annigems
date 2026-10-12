{{-- Create jewellery piece. Codes, QR, and derived weights are assigned on save. --}}
@extends('layouts.branch')

@section('title', 'Add item')
@section('page-title', 'Add item')

@section('content')
<x-page-header title="Add item" subtitle="One physical piece. Pricing is not captured here.">
    <x-slot:actions>
        <x-btn href="{{ route('branch.jewellery-items.index') }}" variant="secondary">Back</x-btn>
    </x-slot:actions>
</x-page-header>

<form method="POST" action="{{ route('branch.jewellery-items.store') }}" enctype="multipart/form-data" data-validate-form data-loading-form data-jewellery-form>
    @csrf
    @include('branch.jewellery-items._form')
</form>
@endsection
