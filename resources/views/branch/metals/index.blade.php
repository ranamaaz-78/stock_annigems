{{-- Tabbed metal types / purities workspace (global catalog). --}}
@extends('layouts.branch')

@section('title', 'Metals')
@section('page-title', 'Metals')

@section('content')
<x-page-header title="Metals & Purities" subtitle="Shared vocabulary for every branch. Purity fineness is stored for later quotation and is not used on item forms.">
    <x-slot:actions>
        <x-btn href="{{ route('branch.jewellery-items.index') }}" variant="secondary">Back to inventory</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4"
     data-metal-purities
     data-can-manage="{{ $canManage ? '1' : '0' }}">
    <div class="flex flex-wrap gap-2 mb-4" role="tablist">
        <button type="button" class="btn btn--secondary is-active" data-tab="metals">Metals</button>
        <button type="button" class="btn btn--secondary" data-tab="purities">Purities</button>
    </div>

    <div data-tab-panel="metals">
        @if($canManage)
            <div class="flex justify-end mb-3">
                <x-btn type="button" data-add-metal>Add metal</x-btn>
            </div>
        @endif
        <table class="table-admin">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Items</th>
                    <th>Order</th>
                    @if($canManage)
                        <th></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($metals as $metal)
                    <tr>
                        <td>{{ $metal->name }}</td>
                        <td><x-status-badge :status="$metal->is_active ? 'active' : 'inactive'" /></td>
                        <td class="font-data-mono">{{ $metal->jewellery_items_count }}</td>
                        <td class="font-data-mono">{{ $metal->sort_order }}</td>
                        @if($canManage)
                            <td>
                                <button type="button" class="form-link text-body-sm" data-edit-metal
                                    data-uuid="{{ $metal->uuid }}"
                                    data-name="{{ $metal->name }}"
                                    data-active="{{ $metal->is_active ? '1' : '0' }}"
                                    data-sort="{{ $metal->sort_order }}"
                                    data-items="{{ $metal->jewellery_items_count }}">Edit</button>
                                ·
                                <button type="button" class="form-link text-body-sm" data-delete-metal
                                    data-uuid="{{ $metal->uuid }}"
                                    data-name="{{ $metal->name }}"
                                    data-items="{{ $metal->jewellery_items_count }}">Delete</button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div data-tab-panel="purities" class="hidden">
        @if($canManage)
            <div class="flex justify-end mb-3">
                <x-btn type="button" data-add-purity>Add purity</x-btn>
            </div>
        @endif
        <table class="table-admin">
            <thead>
                <tr>
                    <th>Metal</th>
                    <th>Name</th>
                    <th>Fineness</th>
                    <th>Status</th>
                    <th>Items</th>
                    <th>Order</th>
                    @if($canManage)
                        <th></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($purities as $purity)
                    <tr>
                        <td>{{ $purity->metalType?->name }}</td>
                        <td>{{ $purity->name }}</td>
                        <td class="font-data-mono">{{ $purity->value }}</td>
                        <td><x-status-badge :status="$purity->is_active ? 'active' : 'inactive'" /></td>
                        <td class="font-data-mono">{{ $purity->jewellery_items_count }}</td>
                        <td class="font-data-mono">{{ $purity->sort_order }}</td>
                        @if($canManage)
                            <td>
                                <button type="button" class="form-link text-body-sm" data-edit-purity
                                    data-uuid="{{ $purity->uuid }}"
                                    data-name="{{ $purity->name }}"
                                    data-value="{{ $purity->value }}"
                                    data-metal="{{ $purity->metal_type_id }}"
                                    data-active="{{ $purity->is_active ? '1' : '0' }}"
                                    data-sort="{{ $purity->sort_order }}"
                                    data-items="{{ $purity->jewellery_items_count }}">Edit</button>
                                ·
                                <button type="button" class="form-link text-body-sm" data-delete-purity
                                    data-uuid="{{ $purity->uuid }}"
                                    data-name="{{ $purity->name }}"
                                    data-items="{{ $purity->jewellery_items_count }}">Delete</button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<x-modal id="metal-modal" title="Metal">
    <form id="metal-form" data-validate-form>
        @csrf
        <input type="hidden" name="_method" value="POST">
        <x-form.input name="name" label="Name" required maxlength="100" />
        <x-form.select name="is_active" label="Status" :options="['1' => 'Active', '0' => 'Inactive']" value="1" required />
        <x-form.input name="sort_order" label="Sort order" type="number" value="0" min="0" />
        <div class="flex justify-end gap-2 mt-4">
            <x-btn type="button" variant="secondary" data-modal-close>Cancel</x-btn>
            <x-btn type="submit" loading-text="Saving...">Save</x-btn>
        </div>
    </form>
</x-modal>

<x-modal id="purity-modal" title="Purity">
    <form id="purity-form" data-validate-form>
        @csrf
        <input type="hidden" name="_method" value="POST">
        <x-form.select name="metal_type_id" label="Metal" :options="$metals->pluck('name', 'id')->all()" required />
        <x-form.input name="name" label="Name" required maxlength="50" help="e.g. 22K or 925" />
        <x-form.input name="value" label="Fineness" type="number" step="0.0001" min="0.0001" max="1" required help="Stored for quotation. e.g. 0.9166 for 22K." />
        <x-form.select name="is_active" label="Status" :options="['1' => 'Active', '0' => 'Inactive']" value="1" required />
        <x-form.input name="sort_order" label="Sort order" type="number" value="0" min="0" />
        <div class="flex justify-end gap-2 mt-4">
            <x-btn type="button" variant="secondary" data-modal-close>Cancel</x-btn>
            <x-btn type="submit" loading-text="Saving...">Save</x-btn>
        </div>
    </form>
</x-modal>
@endsection
