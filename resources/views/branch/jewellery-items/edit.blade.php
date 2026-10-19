{{-- Edit jewellery piece. Stone stock uses allocation deltas only. --}}
@extends('layouts.branch')

@section('title', __('Edit').' '.$item->item_code)
@section('page-title', __('Edit').' '.$item->item_code)

@section('content')
<x-page-header :title="__('Edit').' '.$item->name" :subtitle="$item->item_code">
    <x-slot:actions>
        <x-btn href="{{ route('branch.jewellery-items.show', $item) }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<form method="POST" action="{{ route('branch.jewellery-items.update', $item) }}" enctype="multipart/form-data" data-validate-form data-loading-form data-jewellery-form>
    @csrf
    @method('PUT')
    @include('branch.jewellery-items._form')
</form>
@endsection
