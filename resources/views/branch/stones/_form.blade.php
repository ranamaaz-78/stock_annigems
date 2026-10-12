{{--
    Stone lot fields, matching the supplier inventory sheet.

    Three sections: the stone itself, how it is priced and counted, and
    notes. Pricing mode decides which price row is shown and which figures
    are required. The live total is a preview only; the server recalculates
    it with StonePricingService before anything is stored.

    Used on the register page, the edit page and the intake "New lot" dialog.
--}}
@php
    $stone = $stone ?? null;
    $mode = old('pricing_mode', $stone->pricing_mode?->value ?? \App\Enums\StonePricingMode::PER_PIECE->value);
@endphp
<div
    class="lot-form"
    data-stone-form
    @if($stone) data-stone-uuid="{{ $stone->uuid }}" @endif
>
    {{-- 1. The stone --}}
    <section class="lot-section">
        <h3 class="lot-section__title">
            <span class="material-symbols-outlined icon icon--sm">diamond</span>
            Stone
        </h3>
        <div class="lot-grid">
            <div class="lot-span-2">
                <x-form.input name="name" label="Material / name" :value="$stone->name ?? null" required maxlength="150" placeholder="e.g. CITIRINO" />
            </div>
            <x-form.input name="shape" label="Shape / cut" :value="$stone->shape ?? null" maxlength="60" placeholder="OVAL, PEAR, ROUND" />
            <x-form.input name="dimension" label="Dimension (mm)" :value="$stone->dimension ?? null" maxlength="40" placeholder="8x6" />

            <div class="lot-span-2">
                <x-form.input name="image_code" label="Image code" :value="$stone->image_code ?? null" maxlength="180" placeholder="stone1" help="Name of a picture in Stones → Images, without .jpg." />
            </div>
            <x-form.input name="tray_no" label="Tray no." :value="$stone->tray_no ?? null" maxlength="30" placeholder="K1" />
            <x-form.select name="status" label="Status" :options="$statuses" :value="old('status', $stone->status?->value ?? \App\Enums\StoneStatus::IN_STOCK->value)" required />
        </div>
    </section>

    {{-- 2. Pricing and stock --}}
    <section class="lot-section">
        <h3 class="lot-section__title">
            <span class="material-symbols-outlined icon icon--sm">payments</span>
            Pricing &amp; stock
        </h3>
        <div class="lot-grid">
            <div class="lot-span-2">
                <x-form.select
                    name="pricing_mode"
                    label="Priced by"
                    :options="$pricingModes"
                    :value="$mode"
                    required
                    data-pricing-mode
                />
            </div>

            {{-- Per carat: carat and a carat rate. --}}
            <div class="stone-fields" data-mode-fields="per_carat">
                <x-form.input name="carat" label="Carat (weight)" type="number" :value="$stone->carat ?? null" step="0.001" min="0.001" :max="config('annigems.stones.carat_max')" data-calc-input help="Carat is the weight. Do not enter grams." />
                <x-form.input name="price_per_carat" label="Price per carat" type="number" :value="$stone->price_per_carat ?? null" step="0.001" min="0" data-calc-input />
            </div>

            {{-- Per piece: a single piece rate. --}}
            <div class="stone-fields" data-mode-fields="per_piece">
                <x-form.input name="price_per_piece" label="Price per piece" type="number" :value="$stone->price_per_piece ?? null" step="0.001" min="0" data-calc-input />
            </div>

            {{-- Per gram: bulk weight and a gram rate. --}}
            <div class="stone-fields" data-mode-fields="per_gram">
                <x-form.input name="weight_grams" label="Weight (grams)" type="number" :value="$stone->weight_grams ?? null" step="0.001" min="0" data-calc-input />
                <x-form.input name="price_per_gram" label="Price per gram" type="number" :value="$stone->price_per_gram ?? null" step="0.001" min="0" data-calc-input />
            </div>

            {{-- Pieces drive both the per-carat total and the on-hand stock count. --}}
            <div class="stone-fields" data-pieces-field>
                <x-form.input name="pieces" label="Total pieces" type="number" :value="old('pieces', $stone->quantity ?? null)" min="1" step="1" data-calc-input help="Sets on-hand stock." />
            </div>
            <x-form.input name="pairs" label="Pairs" type="number" :value="$stone->pairs ?? 0" min="0" step="1" help="Counted only; never part of the price." />

            @if($stone)
                <x-form.select name="is_active" label="Active" :options="['1' => 'Active', '0' => 'Inactive']" :value="$stone->is_active ? '1' : '0'" required />

                {{-- Filled in automatically when the price above is changed. --}}
                <div class="form-field lot-span-2">
                    <label class="form-label" for="last_rate_display">Last price</label>
                    <input id="last_rate_display" type="text" class="input-field" value="{{ $stone->lastRateLabel() }}" readonly tabindex="-1">
                    <p class="form-help">
                        @if($stone->rate_changed_at)
                            Replaced on {{ setting_datetime($stone->rate_changed_at) }}. The price above is saved here the next time you change it.
                        @else
                            Not changed yet. The current price is kept here when you change it.
                        @endif
                    </p>
                </div>
            @endif

            {{-- Read-only preview of what the server will store. --}}
            <div class="lot-span-all">
                <div class="stone-calc">
                    <span class="stone-calc__label">Calculated total</span>
                    <span class="stone-calc__formula" data-calc-formula>Fill in the priced fields to see the calculation.</span>
                    <span class="stone-calc__total" data-calc-total>—</span>
                </div>
            </div>
        </div>
    </section>

    {{-- 3. Notes --}}
    <section class="lot-section">
        <h3 class="lot-section__title">
            <span class="material-symbols-outlined icon icon--sm">notes</span>
            Notes
        </h3>
        <x-form.textarea name="remarks" label="Remarks" :value="$stone->remarks ?? null" rows="2" maxlength="255" />
    </section>
</div>
