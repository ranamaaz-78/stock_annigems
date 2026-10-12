{{-- Public jewellery product page. No price, staff, branch, notes, or stone codes. --}}
@extends('layouts.public')

@section('title', $visible ? $payload['name'] : 'Item')

@section('content')
@if(! $visible)
    <div class="p-8 text-center">
        <span class="material-symbols-outlined icon icon--lg icon--secondary">diamond</span>
        <p class="text-headline-sm mt-4">This item is no longer available</p>
    </div>
@else
    @php
        $images = $item->images->values();
        $hero = $images->firstWhere('is_primary', true) ?? $images->first();
        $heroIndex = $hero ? $images->search(fn ($img) => $img->id === $hero->id) : null;
    @endphp
    <div class="w-full aspect-square bg-surface-variant relative">
        @if($heroIndex !== null && $heroIndex !== false)
            <img class="w-full h-full object-cover" src="{{ route('public.jewellery-items.image', ['qrToken' => $qrToken, 'photo' => $heroIndex]) }}" alt="">
        @endif
        <div class="absolute top-4 right-4">
            <x-status-badge :status="$item->status" />
        </div>
    </div>
    @if($images->count() > 1)
        <div class="grid grid-cols-4 gap-1 p-2">
            @foreach($images as $index => $image)
                <img class="aspect-square object-cover" src="{{ route('public.jewellery-items.image', ['qrToken' => $qrToken, 'photo' => $index]) }}" alt="">
            @endforeach
        </div>
    @endif
    <div class="p-6 border-b border-outline-variant">
        <h1 class="text-display mb-2">{{ $payload['name'] }}</h1>
        <p class="text-body-sm text-on-surface-variant">{{ $payload['category'] }}@if($payload['sub_category']) / {{ $payload['sub_category'] }}@endif</p>
    </div>
    <div class="p-6">
        <h2 class="text-label-caps text-on-surface-variant mb-4">Item specifications</h2>
        <dl class="flex flex-col gap-3">
            <div class="flex justify-between py-2 border-b border-outline-variant">
                <dt class="text-body-sm text-on-surface-variant">Metal</dt>
                <dd class="text-body-md">{{ $payload['metal'] }}</dd>
            </div>
            <div class="flex justify-between py-2 border-b border-outline-variant">
                <dt class="text-body-sm text-on-surface-variant">Purity</dt>
                <dd class="text-body-md">{{ $payload['purity'] }}</dd>
            </div>
            <div class="flex justify-between py-2 border-b border-outline-variant">
                <dt class="text-body-sm text-on-surface-variant">Gross weight</dt>
                <dd class="font-data-mono">{{ $payload['gross_weight'] }} g</dd>
            </div>
            <div class="flex justify-between py-2 border-b border-outline-variant">
                <dt class="text-body-sm text-on-surface-variant">Net weight</dt>
                <dd class="font-data-mono">{{ $payload['net_weight'] }} g</dd>
            </div>
            @foreach($payload['stones'] as $stone)
                <div class="flex justify-between py-2 border-b border-outline-variant">
                    <dt class="text-body-sm text-on-surface-variant">{{ $stone['type'] }}{{ $stone['shape'] ? ' · '.$stone['shape'] : '' }}</dt>
                    <dd class="text-body-md">{{ $stone['carat'] }} ct × {{ $stone['quantity'] }}@if($stone['color'] || $stone['clarity']) · {{ trim(($stone['color'] ?? '').' / '.($stone['clarity'] ?? ''), ' /') }}@endif</dd>
                </div>
            @endforeach
        </dl>
    </div>
@endif
@endsection
