{{-- Edit an existing branch creditor. --}}
@extends('layouts.branch')

@section('title', __('Edit Creditor'))
@section('page-title', __('Edit Creditor'))

@section('content')
<x-page-header :title="__('Edit').' '.$creditor->name" :subtitle="$creditor->creditor_code">
    <x-slot:actions>
        <x-btn href="{{ route('branch.creditors.show', $creditor) }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel">
    <div class="panel__body">
        <form method="POST" action="{{ route('branch.creditors.update', $creditor) }}" data-validate-form data-loading-form>
            @csrf
            @method('PUT')
            @include('branch.creditors._form', ['creditor' => $creditor])

            <div class="form-actions">
                <x-btn href="{{ route('branch.creditors.show', $creditor) }}" variant="secondary">{{ __('Cancel') }}</x-btn>
                <x-btn type="submit" :loading-text="__('Saving...')">{{ __('Update creditor') }}</x-btn>
            </div>
        </form>
    </div>
</div>
@endsection
