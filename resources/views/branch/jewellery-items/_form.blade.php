{{-- Dense jewellery item form. Stone rows are allocated packets, not free-text; their cost shows only to users who may see cost. --}}
@php
    $item = $item ?? null;
    $showCost = auth()->user()?->hasPermission('items.view_cost') ?? false;
    $allocated = $item
        ? $item->itemStones->map(function ($row) {
            $stone = $row->stone;
            return [
                'uuid' => $stone?->uuid,
                'stone_code' => $row->stone_code_snapshot,
                'name' => $row->stone_name_snapshot,
                'carat' => (string) $row->carat_snapshot,
                'quantity' => (int) $row->quantity,
                'available' => (int) ($stone?->quantity ?? 0),
                'mode' => $row->pricing_mode?->value ?? $stone?->pricing_mode?->value,
                'grams' => $row->weight_grams === null ? null : (string) $row->weight_grams,
                'available_grams' => $stone?->weight_grams === null ? null : (string) $stone->weight_grams,
                'rate' => $showCost ? (string) $row->unit_rate : null,
                'cost' => $showCost ? (string) $row->line_cost : null,
                'type' => $row->stone_type_snapshot,
                'shape' => $row->shape_snapshot,
            ];
        })->values()
        : collect();
@endphp

<div class="grid grid-cols-1 lg:grid-cols-12 gap-4"
     data-jewellery-root
     data-lookup-url="{{ route('branch.jewellery-items.stones.lookup') }}"
     data-search-url="{{ route('branch.jewellery-items.stones.search') }}"
     data-subs-url="{{ url('/branch/jewellery-items/categories/__UUID__/sub-categories') }}"
     data-purities-url="{{ url('/branch/metals/__UUID__/purities') }}"
     data-grams-per-carat="{{ $gramsPerCarat }}"
     data-show-cost="{{ $showCost ? '1' : '0' }}"
     data-scanner="{{ json_encode($scanner) }}"
     data-allocated="{{ $allocated->toJson() }}"
     data-selected-sub="{{ old('sub_category_id', $item->sub_category_id ?? '') }}"
     data-selected-purity="{{ old('purity_id', $item->purity_id ?? '') }}">

    <div class="lg:col-span-8 bg-surface-container-lowest border border-outline-variant rounded-lg p-4 flex flex-col gap-6">
        <section>
            <h2 class="text-headline-sm border-b border-outline-variant pb-2 mb-3">Basic info</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div data-field class="form-field">
                    <label for="category_id" class="form-label">Category<span class="form-label__required"> *</span></label>
                    <select id="category_id" name="category_id" required class="input-field {{ $errors->has('category_id') ? 'is-invalid' : '' }}" data-category>
                        <option value="">Select…</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" data-uuid="{{ $category->uuid }}" @selected((string) old('category_id', $item->category_id ?? '') === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <p data-error-for="category_id" class="form-error {{ $errors->has('category_id') ? '' : 'is-hidden' }}">{{ $errors->first('category_id') }}</p>
                </div>
                <div data-field class="form-field">
                    <label for="sub_category_id" class="form-label">Sub-category</label>
                    <select id="sub_category_id" name="sub_category_id" class="input-field" data-sub-category>
                        <option value="">None</option>
                    </select>
                    <p data-error-for="sub_category_id" class="form-error {{ $errors->has('sub_category_id') ? '' : 'is-hidden' }}">{{ $errors->first('sub_category_id') }}</p>
                </div>
                <div class="sm:col-span-2">
                    <x-form.input name="name" label="Name" :value="$item->name ?? null" required maxlength="200" />
                </div>
                <div data-field class="form-field">
                    <label class="form-label">Item code</label>
                    <input type="text" class="input-field" value="{{ $item->item_code ?? 'Assigned on save' }}" readonly>
                </div>
                <x-form.select name="status" label="Status" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="old('status', $item?->status->value ?? 'available')" required />
            </div>
        </section>

        <section>
            <h2 class="text-headline-sm border-b border-outline-variant pb-2 mb-3">Metal &amp; weight</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <div data-field class="form-field">
                    <label for="metal_type_id" class="form-label">Metal type<span class="form-label__required"> *</span></label>
                    <select id="metal_type_id" name="metal_type_id" required class="input-field {{ $errors->has('metal_type_id') ? 'is-invalid' : '' }}" data-metal>
                        <option value="">Select…</option>
                        @foreach($metals as $metal)
                            <option value="{{ $metal->id }}" data-uuid="{{ $metal->uuid }}" @selected((string) old('metal_type_id', $item->metal_type_id ?? '') === (string) $metal->id)>{{ $metal->name }}</option>
                        @endforeach
                    </select>
                    <p data-error-for="metal_type_id" class="form-error {{ $errors->has('metal_type_id') ? '' : 'is-hidden' }}">{{ $errors->first('metal_type_id') }}</p>
                </div>
                <div data-field class="form-field">
                    <label for="purity_id" class="form-label">Purity<span class="form-label__required"> *</span></label>
                    <select id="purity_id" name="purity_id" required class="input-field {{ $errors->has('purity_id') ? 'is-invalid' : '' }}" data-purity>
                        <option value="">Select…</option>
                    </select>
                    <p data-error-for="purity_id" class="form-error {{ $errors->has('purity_id') ? '' : 'is-hidden' }}">{{ $errors->first('purity_id') }}</p>
                </div>
                <x-form.input name="gross_weight" label="Gross weight (g)" type="number" :value="$item->gross_weight ?? null" required step="0.001" min="0.001" :max="config('annigems.jewellery.gross_weight_max')" data-gross-weight />
                {{-- Typed gold/metal grams; stored as net_weight for pricing. --}}
                <x-form.input name="metal_weight" label="Metal weight (g)" type="number" :value="old('metal_weight', $item->net_weight ?? null)" required step="0.001" min="0.001" :max="config('annigems.jewellery.gross_weight_max')" data-metal-weight />
                <div data-field class="form-field">
                    <label class="form-label">Stone weight (ct / g)</label>
                    <input type="text" class="input-field font-data-mono" data-stone-weight readonly value="{{ $item ? $item->stone_weight_carat.' ct / '.$item->stone_weight_grams.' g' : '0.000 ct / 0.000 g' }}">
                    <p data-weight-warning class="form-error is-hidden">Stone grams cannot exceed gross weight.</p>
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-headline-sm border-b border-outline-variant pb-2 mb-3">Stones</h2>
            <p class="text-body-sm text-on-surface-variant mb-3">Scan a packet barcode or search by name. Scanning an already-added packet increases its quantity. A stone priced per gram is added by the grams used. Stock is deducted only when you save.@if($showCost) The price of each stone is worked out from its own rate.@endif</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                <div data-scan-wrap>
                    <label class="form-label" for="jewellery-scan">Scan packet</label>
                    <div class="flex gap-2">
                        <input id="jewellery-scan" type="text" class="input-field" data-scan-input autocomplete="off" placeholder="Scan or type stone code">
                        <x-btn type="button" variant="secondary" data-lookup-button>Lookup</x-btn>
                    </div>
                    <p data-scan-message class="form-error is-hidden mt-1"></p>
                </div>
                <div>
                    <label class="form-label" for="jewellery-search">Search by name</label>
                    {{-- Select2 AJAX: search registered stones without dumping the whole catalog. --}}
                    <select
                        id="jewellery-search"
                        class="input-field"
                        data-stone-search
                        data-ajax-url="{{ route('branch.jewellery-items.stones.search') }}"
                        data-ajax-collection="stones"
                    >
                        <option value="">Search by name or code…</option>
                    </select>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="table-admin stone-alloc">
                    <thead>
                        <tr>
                            <th>Stone</th>
                            <th class="table-admin__num">Carat</th>
                            <th>Used</th>
                            <th class="table-admin__num">Available</th>
                            @if($showCost)
                                <th class="table-admin__num">Rate</th>
                                <th class="table-admin__num">Cost</th>
                            @endif
                            <th></th>
                        </tr>
                    </thead>
                    <tbody data-stone-rows></tbody>
                    @if($showCost)
                        <tfoot>
                            <tr>
                                <th colspan="5" class="stone-alloc__total-label">Total stone cost</th>
                                <td class="table-admin__num font-data-mono" data-stone-cost-total>0.000</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <p data-error-for="stones" class="form-error {{ $errors->has('stones') ? '' : 'is-hidden' }}">{{ $errors->first('stones') }}</p>
            <div data-stone-fields></div>
        </section>

        <section>
            <h2 class="text-headline-sm border-b border-outline-variant pb-2 mb-3">Photos</h2>
            <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple class="input-field" data-image-input>
            <p class="form-help">JPEG, PNG, or WebP. At most {{ config('annigems.jewellery.image_max_count') }} photos, {{ config('annigems.jewellery.image_max_kilobytes') }} KB each. Stored privately.</p>
            <p data-error-for="images" class="form-error {{ $errors->has('images') ? '' : 'is-hidden' }}">{{ $errors->first('images') }}</p>
            @if($item && $item->images->isNotEmpty())
                <div class="grid grid-cols-4 gap-2 mt-3">
                    @foreach($item->images as $image)
                        <label class="border border-outline-variant rounded p-2">
                            <img src="{{ route('branch.jewellery-items.images.show', [$item, $image]) }}" alt="" class="w-full aspect-square object-cover mb-2">
                            <span class="text-body-sm flex items-center gap-1">
                                <input type="radio" name="primary_image_id" value="{{ $image->id }}" @checked($image->is_primary)>
                                Primary
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
            <div class="mt-3">
                <x-form.textarea name="description" label="Description" :value="$item->description ?? null" rows="3" maxlength="2000" />
            </div>
        </section>

        <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <x-btn href="{{ $item ? route('branch.jewellery-items.show', $item) : route('branch.jewellery-items.index') }}" variant="secondary">Cancel</x-btn>
            <x-btn type="submit" loading-text="Saving...">{{ $item ? 'Save changes' : 'Save item' }}</x-btn>
        </div>
    </div>

    <aside class="lg:col-span-4 bg-surface-container-lowest border border-outline-variant rounded-lg p-4 text-center">
        <p class="form-label mb-2">Barcode / QR</p>
        @if($item ?? false)
            <img src="{{ $barcodeUri }}" alt="Barcode {{ $item->item_code }}" class="mx-auto mb-2">
            <p class="font-data-mono text-body-sm mb-3">{{ $item->item_code }}</p>
            <img src="{{ $qrUri }}" alt="QR" class="mx-auto" width="140" height="140">
            <p class="text-body-sm text-on-surface-variant mt-2 break-all">{{ $publicUrl }}</p>
        @else
            <p class="text-body-sm text-on-surface-variant">Barcode and public QR are generated after you save.</p>
        @endif
    </aside>
</div>
