{{-- Branch jewellery inventory list. One row per physical piece; no prices. --}}
@extends('layouts.branch')

@section('title', __('Inventory'))
@section('page-title', __('Inventory'))

@section('content')
<x-page-header :title="__('Inventory')" :subtitle="__('Each row is one physical piece. Sold is shown if a later module set it — it cannot be assigned here.')">
    <x-slot:actions>
        @can('create', \App\Models\JewelleryItem::class)
            <x-btn href="{{ route('branch.jewellery-items.create') }}">{{ __('Add item') }}</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <x-filter.search name="q" :value="$filters['q'] ?? ''" :placeholder="__('Code, barcode, or name…')" :aria-label="__('Search inventory')" />
    <select name="category_id" class="input-field" aria-label="{{ __('Category') }}">
        <option value="">{{ __('All categories') }}</option>
        @foreach($categories as $category)
            <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
    <select name="metal_type_id" class="input-field" aria-label="{{ __('Metal') }}">
        <option value="">{{ __('All metals') }}</option>
        @foreach($metals as $metal)
            <option value="{{ $metal->id }}" @selected((string) ($filters['metal_type_id'] ?? '') === (string) $metal->id)>{{ $metal->name }}</option>
        @endforeach
    </select>
    <select name="status" class="input-field" aria-label="{{ __('Status') }}">
        <option value="">{{ __('Any status') }}</option>
        @foreach(\App\Enums\JewelleryItemStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <select name="has_stones" class="input-field" aria-label="{{ __('Stones') }}">
        <option value="">{{ __('Stones: any') }}</option>
        <option value="1" @selected(($filters['has_stones'] ?? '') === '1')>{{ __('With stones') }}</option>
        <option value="0" @selected(($filters['has_stones'] ?? '') === '0')>{{ __('No stones') }}</option>
    </select>
    <input type="number" name="weight_min" value="{{ $filters['weight_min'] ?? '' }}" placeholder="{{ __('Gross min (g)') }}" class="input-field" step="0.001" min="0" aria-label="{{ __('Gross min') }}">
    <input type="number" name="weight_max" value="{{ $filters['weight_max'] ?? '' }}" placeholder="{{ __('Gross max (g)') }}" class="input-field" step="0.001" min="0" aria-label="{{ __('Gross max') }}">
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('branch.jewellery-items.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($items->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No jewellery items yet')" :message="__('Register a piece to print a tag. Pricing is handled later in Quotations — this list stores metal, weight, and stones only.')" icon="inventory_2">
                <x-slot:actions>
                    @can('create', \App\Models\JewelleryItem::class)
                        <x-btn href="{{ route('branch.jewellery-items.create') }}">{{ __('Add item') }}</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <form method="GET" action="{{ route('branch.jewellery-items.labels.bulk', 'barcode') }}" target="_blank" id="bulk-label-form">
            <div class="panel__scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th></th>
                            <th>{{ __('Item') }}</th>
                            <th>{{ __('Category') }}</th>
                            <th>{{ __('Metal') }}</th>
                            <th class="table-admin__num">{{ __('Gross (g)') }}</th>
                            <th class="table-admin__num">{{ __('Metal weight (g)') }}</th>
                            <th class="table-admin__num">{{ __('Stones') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                            <tr>
                                <td>
                                    <input type="checkbox" name="uuids[]" value="{{ $item->uuid }}" class="form-check">
                                </td>
                                <td>
                                    <x-table.identity
                                        :href="route('branch.jewellery-items.show', $item)"
                                        :title="$item->name"
                                        :meta="$item->item_code"
                                        icon="diamond"
                                    />
                                </td>
                                <td>{{ $item->category?->name }}@if($item->subCategory) / {{ $item->subCategory->name }}@endif</td>
                                <td>{{ $item->metalType?->name }} {{ $item->purity?->name }}</td>
                                <td class="table-admin__num">{{ $item->gross_weight }}</td>
                                <td class="table-admin__num">{{ $item->net_weight }}</td>
                                <td class="table-admin__num">{{ $item->item_stones_count }}</td>
                                <td><x-status-badge :status="$item->status" /></td>
                                <td class="table-admin__actions-col">
                                    <x-table.actions>
                                        <x-table.action :href="route('branch.jewellery-items.show', $item)" />
                                        @can('printLabel', $item)
                                            @if($item->barcode)
                                                <x-table.action :href="route('branch.jewellery-items.label', [$item, 'barcode'])" icon="barcode" :label="__('Barcode')" target="_blank" />
                                            @endif
                                            @if($item->qr_token)
                                                <x-table.action :href="route('branch.jewellery-items.label', [$item, 'qr'])" icon="qr_code_2" :label="__('QR')" target="_blank" />
                                            @endif
                                        @endcan
                                        @can('delete', $item)
                                            <button type="submit" form="delete-item-{{ $item->uuid }}" class="table-admin__action table-admin__action--danger" aria-label="{{ __('Delete') }}" title="{{ __('Delete') }}">
                                                <span class="material-symbols-outlined icon icon--sm">delete</span>
                                            </button>
                                        @elseif(auth()->user()->hasPermission('jewellery.delete'))
                                            {{-- Reserved, sold, on memo, delivered or transferred: the piece belongs to a document. --}}
                                            <span class="table-admin__action table-admin__action--locked" role="img" tabindex="0"
                                                  aria-label="{{ __(':status: cannot be deleted', ['status' => $item->status->label()]) }}"
                                                  title="{{ __(':status: this piece is in use and cannot be deleted', ['status' => $item->status->label()]) }}">
                                                <span class="material-symbols-outlined icon icon--sm">lock</span>
                                            </span>
                                        @endcan
                                    </x-table.actions>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(auth()->user()->hasPermission('jewellery.barcode'))
                <div class="panel__toolbar">
                    <x-btn type="submit" variant="secondary">{{ __('Print selected barcodes') }}</x-btn>
                    <x-btn type="submit" variant="secondary" formaction="{{ route('branch.jewellery-items.labels.bulk', 'qr') }}">{{ __('Print selected QR') }}</x-btn>
                    <x-btn type="submit" variant="secondary" formaction="{{ route('branch.jewellery-items.labels.bulk', 'both') }}">{{ __('Print selected both') }}</x-btn>
                </div>
            @endif
        </form>
        @foreach($items as $item)
            @can('delete', $item)
                <form id="delete-item-{{ $item->uuid }}" method="POST" action="{{ route('branch.jewellery-items.destroy', $item) }}" data-loading-form data-confirm-delete-item data-stones-count="{{ $item->item_stones_count }}" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
            @endcan
        @endforeach
        @if($items->total() > 0)
            <div class="panel__footer">
                {{ $items->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
