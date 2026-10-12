{{-- Edit an existing branch creditor. --}}
@extends('layouts.branch')

@section('title', 'Edit Creditor')
@section('page-title', 'Edit Creditor')

@section('content')
<x-page-header title="Edit {{ $creditor->name }}" :subtitle="$creditor->creditor_code">
    <x-slot:actions>
        <x-btn href="{{ route('branch.creditors.show', $creditor) }}" variant="secondary">Back</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel">
    <div class="panel__body">
        <form method="POST" action="{{ route('branch.creditors.update', $creditor) }}" data-validate-form data-loading-form>
            @csrf
            @method('PUT')
            @include('branch.creditors._form', ['creditor' => $creditor])

            <div class="form-actions">
                <x-btn href="{{ route('branch.creditors.show', $creditor) }}" variant="secondary">Cancel</x-btn>
                <x-btn type="submit" loading-text="Saving...">Update creditor</x-btn>
            </div>
        </form>
    </div>
</div>
@endsection
