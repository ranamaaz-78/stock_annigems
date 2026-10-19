{{-- Split-page category / sub-category workspace (global catalog). --}}
@extends('layouts.branch')

@section('title', __('Categories'))
@section('page-title', __('Categories'))

@section('content')
{{-- Wrapper includes the header so Add category is inside the JS root. --}}
<div data-categories
     data-subs-url="{{ url('/branch/categories/__UUID__/sub-categories') }}"
     data-can-manage="{{ $canManage ? '1' : '0' }}">
<x-page-header :title="__('Categories')" :subtitle="__('Shared by every branch. Deactivate a value that is in use; delete only when nothing references it.')">
    <x-slot:actions>
        <x-btn href="{{ route('branch.jewellery-items.index') }}" variant="secondary">{{ __('Back to inventory') }}</x-btn>
        @if($canManage)
            <x-btn type="button" data-add-category="">{{ __('Add category') }}</x-btn>
        @endif
    </x-slot:actions>
</x-page-header>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
    <div class="lg:col-span-4 bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden">
        <h2 class="text-headline-sm px-4 py-3 border-b border-outline-variant">{{ __('Categories') }}</h2>
        @if($categories->isEmpty())
            <div class="p-4">
                <x-empty-state :title="__('No categories yet')" :message="__('Add a category such as Rings or Necklaces, then add sub-categories.')" icon="category" />
            </div>
        @else
            <ul>
                @foreach($categories as $category)
                    <li>
                        <button type="button"
                            class="w-full text-left px-4 py-3 border-b border-outline-variant {{ $selected && $selected->id === $category->id ? 'bg-surface-container-low' : '' }}"
                            data-select-category
                            data-uuid="{{ $category->uuid }}"
                            data-name="{{ $category->name }}"
                            data-description="{{ $category->description }}"
                            data-active="{{ $category->is_active ? '1' : '0' }}"
                            data-sort="{{ $category->sort_order }}"
                            data-items="{{ $category->jewellery_items_count }}"
                            data-subs="{{ $category->sub_categories_count }}">
                            <span class="flex justify-between gap-2">
                                <span>{{ $category->name }}</span>
                                <x-status-badge :status="$category->is_active ? 'active' : 'inactive'" />
                            </span>
                            <span class="text-body-sm text-on-surface-variant">{{ trans_choice(':count item|:count items', $category->jewellery_items_count) }} · {{ trans_choice(':count sub|:count subs', $category->sub_categories_count) }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="lg:col-span-8 bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden">
        <div class="flex justify-between items-center px-4 py-3 border-b border-outline-variant">
            <div>
                <h2 class="text-headline-sm" data-selected-name>{{ $selected?->name ?? __('Select a category') }}</h2>
                <p class="text-body-sm text-on-surface-variant" data-selected-usage>
                    @if($selected)
                        {{ trans_choice('Used by :count item|Used by :count items', $selected->jewellery_items_count) }}
                    @endif
                </p>
            </div>
            @if($canManage)
                <div class="flex gap-2">
                    <x-btn type="button" variant="secondary" data-edit-category>{{ __('Edit') }}</x-btn>
                    <x-btn type="button" variant="danger" data-delete-category>{{ __('Delete') }}</x-btn>
                    <x-btn type="button" data-add-sub>{{ __('Add sub-category') }}</x-btn>
                </div>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Items') }}</th>
                        <th>{{ __('Order') }}</th>
                        @if($canManage)
                            <th></th>
                        @endif
                    </tr>
                </thead>
                <tbody data-sub-rows>
                    @foreach($subCategories as $sub)
                        <tr data-sub-uuid="{{ $sub->uuid }}"
                            data-name="{{ $sub->name }}"
                            data-description="{{ $sub->description }}"
                            data-active="{{ $sub->is_active ? '1' : '0' }}"
                            data-sort="{{ $sub->sort_order }}"
                            data-items="{{ $sub->jewellery_items_count }}">
                            <td>{{ $sub->name }}</td>
                            <td><x-status-badge :status="$sub->is_active ? 'active' : 'inactive'" /></td>
                            <td class="font-data-mono">{{ $sub->jewellery_items_count }}</td>
                            <td class="font-data-mono">{{ $sub->sort_order }}</td>
                            @if($canManage)
                                <td>
                                    <button type="button" class="form-link text-body-sm" data-edit-sub>{{ __('Edit') }}</button>
                                    ·
                                    <button type="button" class="form-link text-body-sm" data-delete-sub>{{ __('Delete') }}</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<x-modal id="category-modal" :title="__('Category')">
    <form id="category-form" data-validate-form>
        @csrf
        <input type="hidden" name="_method" value="POST">
        <x-form.input name="name" :label="__('Name')" required maxlength="150" />
        <x-form.textarea name="description" :label="__('Description')" rows="2" maxlength="2000" />
        <x-form.select name="is_active" :label="__('Status')" :options="['1' => __('Active'), '0' => __('Inactive')]" value="1" required />
        <x-form.input name="sort_order" :label="__('Sort order')" type="number" value="0" min="0" />
        <div class="flex justify-end gap-2 mt-4">
            <x-btn type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-btn>
            <x-btn type="submit" :loading-text="__('Saving...')">{{ __('Save') }}</x-btn>
        </div>
    </form>
</x-modal>

<x-modal id="sub-modal" :title="__('Sub-category')">
    <form id="sub-form" data-validate-form>
        @csrf
        <input type="hidden" name="_method" value="POST">
        <x-form.input name="name" :label="__('Name')" required maxlength="150" />
        <x-form.textarea name="description" :label="__('Description')" rows="2" maxlength="2000" />
        <x-form.select name="is_active" :label="__('Status')" :options="['1' => __('Active'), '0' => __('Inactive')]" value="1" required />
        <x-form.input name="sort_order" :label="__('Sort order')" type="number" value="0" min="0" />
        <div class="flex justify-end gap-2 mt-4">
            <x-btn type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-btn>
            <x-btn type="submit" :loading-text="__('Saving...')">{{ __('Save') }}</x-btn>
        </div>
    </form>
</x-modal>
</div>
@endsection
