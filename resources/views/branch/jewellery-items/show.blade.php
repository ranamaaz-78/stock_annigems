{{-- Jewellery item detail: snapshots, gated photos, movement timeline. Stone cost is shown only to users who may see cost. --}}
@extends('layouts.branch')

@section('title', $item->item_code)
@section('page-title', $item->item_code)

@section('content')
@php $showStoneCost = auth()->user()?->hasPermission('items.view_cost') ?? false; @endphp
<x-page-header :title="$item->name" :subtitle="$item->item_code">
    <x-slot:actions>
        <x-btn href="{{ route('branch.jewellery-items.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
        @can('update', $item)
            <x-btn href="{{ route('branch.jewellery-items.edit', $item) }}">{{ __('Edit') }}</x-btn>
        @endcan
        @can('printLabel', $item)
            @if($item->barcode)
                <x-btn href="{{ route('branch.jewellery-items.label', [$item, 'barcode']) }}" variant="secondary">{{ __('Print barcode') }}</x-btn>
            @endif
            @if($item->qr_token)
                <x-btn href="{{ route('branch.jewellery-items.label', [$item, 'qr']) }}" variant="secondary">{{ __('Print QR') }}</x-btn>
            @endif
            @if($item->barcode || $item->qr_token)
                <x-btn href="{{ route('branch.jewellery-items.label', [$item, 'both']) }}" variant="secondary">{{ __('Print both') }}</x-btn>
            @endif
        @endcan
        @can('delete', $item)
            <form method="POST" action="{{ route('branch.jewellery-items.destroy', $item) }}" data-loading-form data-confirm-delete-item data-stones-count="{{ $item->itemStones->sum('quantity') }}" class="inline">
                @csrf
                @method('DELETE')
                <x-btn type="submit" variant="danger" :loading-text="__('Deleting...')">{{ __('Delete') }}</x-btn>
            </form>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-4 mb-4">
    <div class="lg:col-span-4 flex flex-col gap-3">
        @php $primary = $item->images->firstWhere('is_primary', true) ?? $item->images->first(); @endphp
        <div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4 aspect-square flex items-center justify-center">
            @if($primary)
                <img src="{{ route('branch.jewellery-items.images.show', [$item, $primary]) }}" alt="" class="max-w-full max-h-full object-contain">
            @else
                <span class="material-symbols-outlined icon icon--lg icon--secondary">image</span>
            @endif
        </div>
        @if($item->images->count() > 1)
            <div class="grid grid-cols-4 gap-2">
                @foreach($item->images as $image)
                    <img src="{{ route('branch.jewellery-items.images.show', [$item, $image]) }}" alt="" class="aspect-square object-cover border border-outline-variant rounded">
                @endforeach
            </div>
        @endif
    </div>

    <div class="lg:col-span-5 bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
        <div class="flex items-center gap-2 mb-3">
            <h2 class="text-headline-sm">{{ $item->name }}</h2>
            <x-status-badge :status="$item->status" />
        </div>
        <dl class="grid grid-cols-2 gap-3 text-body-sm">
            <div><dt class="form-label">{{ __('Category') }}</dt><dd>{{ $item->category?->name }}@if($item->subCategory) / {{ $item->subCategory->name }}@endif</dd></div>
            <div><dt class="form-label">{{ __('Metal') }}</dt><dd>{{ $item->metalType?->name }} {{ $item->purity?->name }}</dd></div>
            <div><dt class="form-label">{{ __('Gross weight') }}</dt><dd class="font-data-mono">{{ $item->gross_weight }} g</dd></div>
            <div><dt class="form-label">{{ __('Stone weight') }}</dt><dd class="font-data-mono">{{ $item->stone_weight_carat }} ct / {{ $item->stone_weight_grams }} g</dd></div>
            <div><dt class="form-label">{{ __('Metal weight') }}</dt><dd class="font-data-mono">{{ $item->net_weight }} g</dd></div>
            <div><dt class="form-label">{{ __('Status') }}</dt><dd>{{ $item->status->label() }}</dd></div>
        </dl>
        @if($item->description)
            <p class="text-body-sm mt-3">{{ $item->description }}</p>
        @endif
        <x-attribution :record="$item" class="mt-4" />

        @can('changeStatus', $item)
            <form method="POST" action="{{ route('branch.jewellery-items.status', $item) }}" data-validate-form data-loading-form class="mt-4 pt-4 border-t border-outline-variant">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-form.select name="status" :label="__('Change status')" :options="collect(\App\Enums\JewelleryItemStatus::usableInThisModule())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$item->status->value" required />
                    <x-form.input name="notes" :label="__('Note (optional)')" maxlength="2000" />
                </div>
                <div class="mt-3">
                    <x-btn type="submit" variant="secondary" :loading-text="__('Updating...')">{{ __('Update status') }}</x-btn>
                </div>
            </form>
        @endcan
    </div>

    <div class="lg:col-span-3 bg-surface-container-lowest border border-outline-variant rounded-lg p-4 text-center">
        <p class="form-label mb-2">{{ __('Item code') }}</p>
        @if($item->barcode && $barcodeUri)
            <img src="{{ $barcodeUri }}" alt="{{ $item->item_code }}" class="mx-auto">
        @endif
        <p class="font-data-mono text-body-sm mt-2 mb-4">{{ $item->item_code }}</p>
        @if($publicUrl && $qrUri)
            <p class="form-label mb-2">{{ __('Public QR') }}</p>
            <img src="{{ $qrUri }}" alt="{{ __('QR') }}" class="mx-auto" width="140" height="140">
            <p class="text-body-sm text-on-surface-variant mt-2 break-all">{{ $publicUrl }}</p>
        @else
            <p class="text-body-sm text-on-surface-variant">{{ __('No public QR is issued for this piece.') }}</p>
        @endif
    </div>
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden mb-4">
    <h2 class="text-headline-sm px-4 py-3 border-b border-outline-variant">{{ __('Allocated stones (snapshots)') }}</h2>
    @if($item->itemStones->isEmpty())
        <p class="p-4 text-body-sm text-on-surface-variant">{{ __('No stones allocated to this piece.') }}</p>
    @else
        <table class="table-admin">
            <thead>
                <tr>
                    <th>{{ __('Code') }}</th>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Shape') }}</th>
                    <th>{{ __('Color') }}</th>
                    <th>{{ __('Clarity') }}</th>
                    <th>{{ __('Carat') }}</th>
                    <th>{{ __('Qty') }}</th>
                    <th>{{ __('Total ct') }}</th>
                    @if($showStoneCost)
                        <th>{{ __('Rate') }}</th>
                        <th>{{ __('Cost') }}</th>
                    @endif
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($item->itemStones as $row)
                    <tr>
                        <td class="font-data-mono">{{ $row->stone_code_snapshot }}</td>
                        <td>{{ $row->stone_name_snapshot }}</td>
                        <td>{{ $row->stone_type_snapshot }}</td>
                        <td>{{ $row->shape_snapshot ?? '—' }}</td>
                        <td>{{ $row->color_snapshot ?? '—' }}</td>
                        <td>{{ $row->clarity_snapshot ?? '—' }}</td>
                        <td class="font-data-mono">{{ $row->carat_snapshot }}</td>
                        <td class="font-data-mono">{{ $row->weight_grams !== null ? rtrim(rtrim((string) $row->weight_grams, '0'), '.').' g' : $row->quantity }}</td>
                        <td class="font-data-mono">{{ $row->total_carat }}</td>
                        @if($showStoneCost)
                            <td class="font-data-mono">{{ $row->pricing_mode ? setting_money($row->unit_rate).' / '.match ($row->pricing_mode) { \App\Enums\StonePricingMode::PER_CARAT => 'ct', \App\Enums\StonePricingMode::PER_GRAM => 'g', default => 'pc' } : '—' }}</td>
                            <td class="font-data-mono">{{ $row->pricing_mode ? setting_money($row->line_cost) : '—' }}</td>
                        @endif
                        <td>
                            @if($row->stone)
                                <a href="{{ route('branch.stones.show', $row->stone) }}" class="form-link text-body-sm">{{ __('Catalog') }}</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            @if($showStoneCost)
                <tfoot>
                    <tr>
                        <th colspan="10" class="text-right">{{ __('Stone cost') }}</th>
                        <th class="font-data-mono">{{ setting_money($item->itemStones->sum('line_cost')) }}</th>
                        <th></th>
                    </tr>
                </tfoot>
            @endif
        </table>
    @endif
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden">
    <h2 class="text-headline-sm px-4 py-3 border-b border-outline-variant">{{ __('History') }}</h2>
    @if($item->movements->isEmpty())
        <p class="p-4 text-body-sm text-on-surface-variant">{{ __('No movements recorded.') }}</p>
    @else
        <table class="table-admin">
            <thead>
                <tr>
                    <th>{{ __('When') }}</th>
                    <th>{{ __('Event') }}</th>
                    <th>{{ __('From') }}</th>
                    <th>{{ __('To') }}</th>
                    <th>{{ __('Notes') }}</th>
                    <th>{{ __('Performed by') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($item->movements as $movement)
                    <tr>
                        <td class="font-data-mono">{{ setting_datetime($movement->created_at) }}</td>
                        <td>{{ $movement->type->label() }}</td>
                        <td>{{ $movement->from_status?->label() ?? '—' }}</td>
                        <td>{{ $movement->to_status?->label() ?? '—' }}</td>
                        <td>{{ $movement->notes ?? '—' }}</td>
                        <td>{{ $movement->performer?->name }} ({{ \App\Support\AttributionFormatter::roleLabel($movement->performed_by_role) }})</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
