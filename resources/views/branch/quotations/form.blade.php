{{--
    Quotation workspace — POS terminal layout from design/pos_terminal.

    Left: scan dock + ticket. Right: customer, live summary, checkout.
    Pricing is recalculated in resources/js/modules/quotation-form.js from
    the JSON config on #quotation-form-root, then re-verified server-side
    by PricingCalculationService.
--}}
@extends('layouts.branch')

@section('title', $quotation ? 'Edit Quotation' : 'New Quotation')
@section('page-title', $quotation ? 'Edit Quotation' : 'New Quotation')

@php
    // Built as a single variable: a multi-line @json(...) argument breaks the
    // Blade directive parser and silently truncates the compiled array.
    $formConfig = [
        'quotationId' => $quotation?->id,
        'quotationUuid' => $quotation?->uuid,
        'payload' => $payload,
        'rateMap' => $rateMap,
        'draftDebounceMs' => $draftDebounceMs,
        'canDiscount' => $canDiscount,
        'maxDiscountPercentage' => $maxDiscountPercentage,
        'taxEnabled' => $taxEnabled ?? true,
        'taxType' => $taxType ?? 'none',
        'taxRate' => $taxRate ?? 0,
        'taxLabel' => $taxLabel ?? 'IVA',
        'ivaRates' => $ivaRates ?? [],
        'recargoRates' => $recargoRates ?? [],
        'currencySymbol' => setting('currency_symbol', 'Rs'),
        'currencyDecimals' => (int) setting('currency_decimals', 2),
        'routes' => [
            'lookup' => route('branch.quotations.lookup'),
            'search' => route('branch.quotations.search'),
            'draftSave' => route('branch.quotations.draft.save'),
            'store' => route('branch.quotations.store'),
            'update' => $quotation ? route('branch.quotations.update', $quotation) : null,
            'show' => route('branch.quotations.show', ['quotation' => '__UUID__']),
            'invoice' => route('branch.quotations.invoice'),
            'invoiceShow' => route('branch.invoices.show', ['invoice' => '__UUID__']),
            'customerSearch' => route('branch.customers.search'),
        ],
    ];

    $selectedCustomerUuid = $payload['customer_uuid'] ?? '';
    $isWalkinEdit = $selectedCustomerUuid === '' && filled($payload['walkin_name'] ?? null);
    $selectedCustomerLabel = trim(implode(' · ', array_filter([
        $payload['customer_name'] ?? null,
        $payload['customer_phone'] ?? null,
        $payload['customer_code'] ?? null,
    ])));
@endphp

@section('content')
{{--
    Config is emitted with {{ json_encode() }} — not @json() — because @json
    leaves the structural double quotes raw, which closes the attribute early
    and makes JSON.parse throw, disabling the whole workspace script.
--}}
<div
    id="quotation-form-root"
    data-config="{{ json_encode($formConfig) }}"
    data-scanner="{{ json_encode($scanner) }}"
>
    <form data-save-form class="sales-workspace">
        <div class="sales-workspace__main">
            <div class="sales-workspace__toolbar">
                <div class="sales-workspace__heading">
                    <h1 class="sales-workspace__title">
                        {{ $quotation ? $quotation->quotation_number : 'New Quotation' }}
                    </h1>
                    <p class="sales-workspace__meta" data-item-count>No items scanned</p>
                </div>
                <x-btn href="{{ route('branch.quotations.index') }}" variant="secondary">Back</x-btn>
            </div>

            {{-- USB scanner / manual code entry. Adds a line via AJAX lookup. --}}
            <div class="scan-dock">
                <label class="form-label" for="quotation-scan">Scan barcode or enter SKU</label>
                <div class="scan-dock__row scan-input-wrap" data-scan-wrap>
                    <span class="scan-dock__icon" aria-hidden="true">
                        <span class="material-symbols-outlined icon icon--md icon--secondary">barcode_scanner</span>
                    </span>
                    <input
                        id="quotation-scan"
                        data-scan-input
                        class="input-field input-field--scan scan-dock__input"
                        placeholder="Ready to scan…"
                        aria-label="Item or stone code"
                        autofocus
                    />
                    <x-btn type="button" data-scan-submit>Add</x-btn>
                </div>
                {{-- Short code = stone packet letters; name search = jewellery or stone. --}}
                <div class="scan-dock__extras">
                    <div>
                        <label class="form-label" for="quotation-short-code">Short code</label>
                        <input
                            id="quotation-short-code"
                            type="text"
                            class="input-field"
                            maxlength="3"
                            autocomplete="off"
                            placeholder="DIA"
                            data-short-code-input
                        >
                    </div>
                    <div>
                        <label class="form-label" for="quotation-item-search">Search by name</label>
                        <select
                            id="quotation-item-search"
                            class="input-field"
                            data-item-search
                            data-ajax-url="{{ route('branch.quotations.search') }}"
                            data-ajax-collection="items"
                        >
                            <option value="">Jewellery or stone name…</option>
                        </select>
                    </div>
                </div>
                <p data-scan-error class="form-error is-hidden"></p>
            </div>

            <div class="sales-workspace__list">
                {{-- Mixed-purity notice, shown by JS when lines differ in purity. --}}
                <div data-purity-note class="alert alert--info hidden"></div>

                <div data-lines-empty class="sales-workspace__empty">
                    <x-empty-state
                        title="Add the first piece"
                        message="Scan a tag, type a 3-letter short code, or search by name."
                        icon="barcode_scanner"
                    />
                </div>

                {{-- Line items are rendered entirely by quotation-form.js. --}}
                <div data-lines class="sale-lines"></div>
            </div>
        </div>

        <aside class="sales-workspace__aside">
            {{-- Customer: Select2 search, or toggle to capture walk-in name/phone. --}}
            <section class="sales-aside__section">
                <div class="customer-picker {{ $isWalkinEdit ? 'is-walkin' : '' }}">
                    <div class="sales-aside__bar">
                        <p class="sales-aside__label">Customer info</p>
                        <label class="sales-aside__text-action">
                            <input type="checkbox" data-walkin-toggle class="sales-aside__text-action-input" @checked($isWalkinEdit)>
                            <span class="sales-aside__text-action-listed">Walk-in</span>
                            <span class="sales-aside__text-action-walkin">Listed customer</span>
                        </label>
                    </div>

                    {{-- Select2 AJAX: search existing customers without dumping the whole list. --}}
                    <div data-customer-select-wrap>
                        <label for="quotation-customer" class="form-label">Customer</label>
                        <select
                            id="quotation-customer"
                            name="customer_uuid"
                            class="input-field"
                            data-customer-select
                            data-ajax-url="{{ route('branch.customers.search') }}"
                            data-ajax-collection="customers"
                            data-error-required="Select a customer or add a walk-in."
                            @unless($isWalkinEdit) required @endunless
                        >
                            <option value="">Search by name or phone…</option>
                            @if($selectedCustomerUuid !== '')
                                <option value="{{ $selectedCustomerUuid }}" selected>{{ $selectedCustomerLabel }}</option>
                            @endif
                        </select>
                        <p data-error-for="customer_uuid" class="form-error is-hidden"></p>
                    </div>

                    <div data-walkin-fields class="form-grid form-grid--2">
                        <div class="field-inline">
                            <label class="field-inline__label" for="walkin-name">Name</label>
                            <input
                                id="walkin-name"
                                name="walkin_name"
                                data-doc="walkin_name"
                                class="input-field"
                                maxlength="150"
                                data-error-required="Enter the walk-in customer name."
                                value="{{ $payload['walkin_name'] ?? '' }}"
                                @if($isWalkinEdit) required @endif
                            />
                        </div>
                        <div class="field-inline">
                            <label class="field-inline__label" for="walkin-phone">Phone</label>
                            <input
                                id="walkin-phone"
                                name="walkin_phone"
                                data-doc="walkin_phone"
                                class="input-field"
                                maxlength="30"
                                data-pattern="^[0-9+\-\s()]{7,30}$"
                                data-error-required="Enter the walk-in customer phone."
                                data-error-pattern="Enter a valid phone number."
                                value="{{ $payload['walkin_phone'] ?? '' }}"
                                @if($isWalkinEdit) required @endif
                            />
                        </div>
                    </div>

                    <div class="field-inline">
                        <label class="field-inline__label" for="quotation-date">Quotation date</label>
                        <input
                            id="quotation-date"
                            type="date"
                            data-doc="quotation_date"
                            class="input-field"
                            value="{{ $payload['quotation_date'] ?? now()->toDateString() }}"
                        />
                    </div>
                </div>
            </section>

            <section class="sales-aside__section sales-aside__section--grow">
                <div class="sales-aside__card">
                    <h2 class="sales-aside__card-title">Quotation summary</h2>

                    @if($canDiscount)
                        <div class="sales-aside__discount">
                            <div class="field-inline">
                                <label class="field-inline__label" for="discount-type">Discount type</label>
                                <select id="discount-type" data-doc="discount_type" class="input-field">
                                    @foreach(\App\Enums\DiscountType::cases() as $type)
                                        <option value="{{ $type->value }}" @selected(($payload['discount_type'] ?? 'none') === $type->value)>{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field-inline">
                                <label class="field-inline__label" for="discount-value">Discount value</label>
                                <input
                                    id="discount-value"
                                    data-doc="discount_value"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="{{ $maxDiscountPercentage }}"
                                    class="input-field"
                                    value="{{ $payload['discount_value'] ?? '0' }}"
                                />
                            </div>
                        </div>
                        <p class="form-help">Discount cannot exceed {{ rtrim(rtrim(number_format((float) $maxDiscountPercentage, 2), '0'), '.') }}%.</p>
                    @else
                        <input type="hidden" data-doc="discount_type" value="none">
                        <input type="hidden" data-doc="discount_value" value="0">
                    @endif

                    {{-- Tax rates are SuperAdmin's lists; the percentage is looked up on the server. --}}
                    <input type="hidden" data-doc="tax_type" value="{{ $taxType ?? 'none' }}">
                    <input type="hidden" data-doc="tax_rate" value="{{ $taxRate ?? 0 }}">
                    @if($taxEnabled ?? false)
                        <div class="form-grid">
                            <div>
                                <label class="form-label" for="tax_rate_uuid">IVA</label>
                                <select id="tax_rate_uuid" class="input-field" data-no-select2 data-doc="tax_rate_uuid">
                                    @foreach($ivaRates as $rate)
                                        <option value="{{ $rate['uuid'] }}" @selected($rate['uuid'] === ($payload['tax_rate_uuid'] ?? null))>{{ $rate['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if(count($recargoRates))
                                <div>
                                    <label class="form-label" for="recargo_rate_uuid">Recargo de equivalencia</label>
                                    <select id="recargo_rate_uuid" class="input-field" data-no-select2 data-doc="recargo_rate_uuid">
                                        <option value="">None</option>
                                        @foreach($recargoRates as $rate)
                                            <option value="{{ $rate['uuid'] }}" @selected($rate['uuid'] === ($payload['recargo_rate_uuid'] ?? null))>{{ $rate['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>
                    @endif

                    <dl class="totals">
                        <div class="totals__row">
                            <dt class="totals__label">Gold value <span data-gold-weight></span></dt>
                            <dd class="totals__value" data-gold-value>0.00</dd>
                        </div>
                        <div class="totals__row">
                            <dt class="totals__label">Stone value</dt>
                            <dd class="totals__value" data-stone-value>0.00</dd>
                        </div>
                        <div class="totals__row totals__row--rule">
                            <dt class="totals__label">Making charges</dt>
                            <dd class="totals__value" data-making-value>0.00</dd>
                        </div>
                        <div class="totals__row">
                            <dt class="totals__label">Subtotal</dt>
                            <dd class="totals__value" data-subtotal>0.00</dd>
                        </div>
                        @if($canDiscount)
                            <div class="totals__row">
                                <dt class="totals__label">Discount</dt>
                                <dd class="totals__value" data-discount-amount>0.00</dd>
                            </div>
                        @endif
                        @if($taxEnabled ?? true)
                            <div class="totals__row totals__row--rule">
                                <dt class="totals__label">IVA</dt>
                                <dd class="totals__value" data-tax-amount>0.00</dd>
                            </div>
                            <div class="totals__row">
                                <dt class="totals__label">Recargo de equivalencia</dt>
                                <dd class="totals__value" data-recargo-amount>0.00</dd>
                            </div>
                        @endif
                        <div class="totals__row totals__row--grand">
                            <dt class="totals__label">Total</dt>
                            <dd class="totals__value" data-grand-total>0.00</dd>
                        </div>
                    </dl>

                    @if($taxEnabled ?? false)
                        <p class="form-help">IVA and recargo are charged on the discounted total, from the rates SuperAdmin keeps.</p>
                    @endif
                </div>
            </section>

            <section class="sales-aside__section sales-aside__section--checkout">
                <div class="sales-aside__notes">
                    <label class="field-inline__label" for="quotation-notes">Notes</label>
                    <textarea id="quotation-notes" data-doc="notes" class="input-field" rows="2">{{ $payload['notes'] ?? '' }}</textarea>
                </div>

                <div class="sales-aside__actions">
                    {{-- Quotation first; invoice is the optional checkout path. --}}
                    @if($canConvert && ! $quotation)
                        <x-btn type="submit" class="btn--tall" loading-text="Saving…" block>
                            <span class="material-symbols-outlined icon icon--sm">request_quote</span>
                            Save quotation
                        </x-btn>
                        <x-btn type="button" variant="secondary" block data-modal-open="invoice-modal">
                            <span class="material-symbols-outlined icon icon--sm">point_of_sale</span>
                            Save invoice
                        </x-btn>
                    @else
                        <x-btn type="submit" class="btn--tall" loading-text="Saving…" block>
                            <span class="material-symbols-outlined icon icon--sm">request_quote</span>
                            Save quotation
                        </x-btn>
                    @endif
                    @if($quotation && $canConvert)
                        <x-btn type="button" variant="secondary" block data-modal-open="convert-modal">Convert to invoice</x-btn>
                    @endif
                </div>
            </section>
        </aside>
    </form>
</div>

@if($canConvert && ! $quotation)
    {{--
        Payment capture for direct invoice. Submit is intercepted by
        quotation-form.js (POST JSON to quotations.invoice). No native
        action — a full-page POST would drop the scanned lines.
    --}}
    <x-modal id="invoice-modal" title="Save invoice">
        <form id="invoice-modal-form">
            <div class="form-grid">
                <x-form.select
                    name="payment_method"
                    label="Payment method"
                    :options="$paymentMethods"
                    required
                    data-error-required="Select a payment method."
                />
                <x-form.input name="payment_reference" label="Reference (optional)" maxlength="100" />
            </div>
            <div class="form-actions">
                <x-btn type="button" variant="secondary" data-modal-close>Cancel</x-btn>
                <x-btn type="submit" loading-text="Saving…">Create invoice</x-btn>
            </div>
        </form>
    </x-modal>
@endif

@if($quotation && $canConvert)
    <x-modal id="convert-modal" title="Convert to invoice">
        <form method="POST" action="{{ route('branch.quotations.convert', $quotation) }}" id="convert-modal-form" data-loading-form>
            @csrf
            <div class="form-grid">
                <x-form.select name="payment_method" label="Payment method" :options="$paymentMethods" required />
                <x-form.input name="payment_reference" label="Reference (optional)" maxlength="100" />
            </div>
            <div class="form-actions">
                <x-btn type="button" variant="secondary" data-modal-close>Cancel</x-btn>
                <x-btn type="submit" loading-text="Converting…">Create invoice</x-btn>
            </div>
        </form>
    </x-modal>
@endif
@endsection
