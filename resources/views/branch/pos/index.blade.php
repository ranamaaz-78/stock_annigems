{{--
    Fullscreen POS ticket.

    The branch sidebar is on the left, no topbar. The document fills the rest:
    identity and issue date, billed-to, scan, lines, then notes beside the
    totals. Generate saves, prints in the hidden iframe, and clears the ticket.
--}}
@extends('layouts.pos')

@section('title', __('POS'))

@php
    $currency = setting('currency_symbol', 'Rs');
    $validityDays = (int) config('annigems.documents.quotation_validity_days');
    $company = setting('company_name', config('annigems.documents.brand_name'));
    $branchLine = collect([$branch?->name, $branch?->address, $branch?->city])->filter(fn ($part) => filled($part))->implode(' · ');
    $branchContact = collect([$branch?->contact_number, $branch?->email])->filter(fn ($part) => filled($part))->implode(' · ');
    $payIcons = [
        'cash' => 'payments',
        'card' => 'credit_card',
        'bank_transfer' => 'account_balance',
        'cheque' => 'receipt',
        'other' => 'more_horiz',
    ];

    $formConfig = [
        'rateMap' => $rateMap,
        'canDiscount' => $canDiscount,
        'maxDiscountPercentage' => $maxDiscountPercentage,
        'taxEnabled' => $taxEnabled ?? false,
        'taxType' => $taxType ?? 'none',
        'taxRate' => $taxRate ?? 0,
        'taxLabel' => $taxLabel ?? 'IVA',
        'ivaRates' => $ivaRates,
        'recargoRates' => $recargoRates,
        'defaultIvaUuid' => $defaultIvaUuid,
        'currencySymbol' => $currency,
        'currencyDecimals' => (int) setting('currency_decimals', 2),
        'quotationDate' => now()->toDateString(),
        // The browser re-renders "Valid until" whenever the cashier changes
        // the issue date, using the same format the printed sheet uses.
        'dateFormat' => (string) setting('date_format', 'd-m-Y'),
        'validityDays' => $validityDays,
        // Invoice when the cashier may convert, otherwise only quotations exist.
        'defaultKind' => $defaultKind,
        'initialCreditor' => $initialCreditor,
        // An invoice or delivery note with no payment yet, opened for editing (null on a new ticket).
        'editingDocument' => $editingDocument ?? null,
        // An issued performa opened for editing (null on a new ticket).
        'editingPerforma' => $editingPerforma ?? null,
        'canDeliver' => $canDeliver,
        'canPerforma' => $canPerforma,
        'documentNumbers' => $documentNumbers,
        'routes' => [
            'lookup' => route('branch.quotations.lookup'),
            'search' => route('branch.quotations.search'),
            'store' => route('branch.quotations.store'),
            'invoice' => route('branch.quotations.invoice'),
            'deliveryNote' => route('branch.delivery-notes.store'),
            'performa' => route('branch.performas.store'),
            'performaUpdate' => ($editingPerforma ?? null) ? route('branch.performas.update', $editingPerforma['uuid']) : null,
            'creditorSearch' => route('branch.creditors.search'),
            'numbers' => route('branch.pos.numbers'),
            'customerLookup' => route('branch.customers.lookup'),
            'customerSearch' => route('branch.customers.search'),
        ],
    ];
@endphp

@section('content')
<div
    id="pos-root"
    class="pos"
    data-config="{{ json_encode($formConfig) }}"
    data-scanner="{{ json_encode($scanner) }}"
>
    <main class="pos-stage">
        <div class="pos-stage__sheet">
            <div data-purity-note class="alert alert--info pos-purity hidden"></div>

            <article class="pos-ticket">
                <div class="pos-ticket__body">
                    {{-- Identity and the document facts. No page header above this. --}}
                    <section class="pos-identity">
                        <div class="pos-brand">
                            <img class="pos-brand__logo" src="{{ brand_logo_url() }}" alt="{{ $company }}">
                            <div class="pos-brand__text">
                                <p class="pos-brand__name">{{ $company }}</p>
                                @if($branchLine !== '')
                                    <p class="pos-brand__meta">{{ $branchLine }}</p>
                                @endif
                                @if($branchContact !== '')
                                    <p class="pos-brand__meta">{{ $branchContact }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="pos-dochead">
                            <div class="pos-dochead__title">
                                <span class="pos-chip pos-chip--kind" data-kind-chip>{{ __('Quotation') }}</span>
                                <span class="pos-chip pos-chip--ok">
                                    <span class="material-symbols-outlined icon icon--xs">verified_user</span>
                                    {{ __('Stock protected') }}
                                </span>
                            </div>
                            <div class="pos-meta">
                                <div class="pos-meta__cell">
                                    <span class="pos-cap">{{ __('Document no.') }}</span>
                                    <span
                                        class="pos-chip pos-chip--draft"
                                        data-preview-number
                                        title="{{ __('Given when you save. If another till saves first, yours moves to the next number.') }}"
                                    >{{ $canConvert ? $documentNumbers['invoice'] : $documentNumbers['quotation'] }}</span>
                                </div>
                                <div class="pos-meta__cell">
                                    <label class="pos-cap" for="pos-issue-date">{{ __('Issue date') }}</label>
                                    <input
                                        id="pos-issue-date"
                                        type="date"
                                        class="pos-date"
                                        value="{{ now()->toDateString() }}"
                                        data-pos-date
                                        aria-label="{{ __('Issue date') }}"
                                    >
                                </div>
                                <div class="pos-meta__cell" data-valid-until-row>
                                    <span class="pos-cap">{{ __('Valid until') }}</span>
                                    <span class="pos-num" data-preview-valid-until>{{ setting_date(now()->copy()->addDays($validityDays)) }}</span>
                                </div>
                                <div class="pos-meta__cell pos-meta__cell--count">
                                    <span class="pos-cap">{{ __('Items') }}</span>
                                    <span class="pos-num" data-preview-count>0</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- Phone filters the cached registry. A miss is just a new customer. --}}
                    <section class="pos-billto" aria-label="{{ __('Customer') }}">
                        {{-- Shown only while an issued performa is open for editing. --}}
                        <div class="pos-edit-banner is-hidden" data-edit-banner></div>
                        {{-- What this ticket becomes. Everything below follows the choice. --}}
                        <div class="pos-kind" role="radiogroup" aria-label="{{ __('Document type') }}" data-kind-group>
                            <button
                                type="button"
                                class="pos-kind__opt"
                                role="radio"
                                aria-checked="false"
                                data-kind="invoice"
                                @disabled(! $canConvert)
                                @if(! $canConvert) title="{{ __('You are not allowed to create invoices') }}" @endif
                            >
                                <span class="material-symbols-outlined icon icon--md" aria-hidden="true">receipt_long</span>
                                <span class="pos-kind__text">
                                    <span class="pos-kind__name">{{ __('Invoice') }}</span>
                                    <span class="pos-kind__sub">{{ __('Takes payment now · deducts stock') }}</span>
                                </span>
                            </button>
                            <button
                                type="button"
                                class="pos-kind__opt"
                                role="radio"
                                aria-checked="false"
                                data-kind="quotation"
                            >
                                <span class="material-symbols-outlined icon icon--md" aria-hidden="true">request_quote</span>
                                <span class="pos-kind__text">
                                    <span class="pos-kind__name">{{ __('Quotation') }}</span>
                                    <span class="pos-kind__sub">{{ trans_choice('Valid :count day · holds stock|Valid :count days · holds stock', $validityDays) }}</span>
                                </span>
                            </button>
                            <button
                                type="button"
                                class="pos-kind__opt"
                                role="radio"
                                aria-checked="false"
                                data-kind="delivery_note"
                                @disabled(! $canDeliver)
                                @if(! $canDeliver) title="{{ __('You are not allowed to create delivery notes') }}" @endif
                            >
                                <span class="material-symbols-outlined icon icon--md" aria-hidden="true">local_shipping</span>
                                <span class="pos-kind__text">
                                    <span class="pos-kind__name">{{ __('Delivery Note') }}</span>
                                    <span class="pos-kind__sub">{{ __('No tax · takes stock now') }}</span>
                                </span>
                            </button>
                            <button
                                type="button"
                                class="pos-kind__opt"
                                role="radio"
                                aria-checked="false"
                                data-kind="performa"
                                @disabled(! $canPerforma)
                                @if(! $canPerforma) title="{{ __('You are not allowed to issue performas') }}" @endif
                            >
                                <span class="material-symbols-outlined icon icon--md" aria-hidden="true">assignment</span>
                                <span class="pos-kind__text">
                                    <span class="pos-kind__name">{{ __('Performa') }}</span>
                                    <span class="pos-kind__sub">{{ __('To a creditor · no discount, no tax') }}</span>
                                </span>
                            </button>
                        </div>

                        <div class="pos-section__head">
                            <span class="pos-secno">01</span>
                            <h2 class="pos-section__title" data-billto-title>{{ __('Billed to') }}</h2>
                            <span data-customer-chip class="pos-chip">{{ __('No customer') }}</span>
                            <span class="pos-section__spacer"></span>
                            {{-- Whether the money is in. Paid asks for the method; Pending records no payment and leaves the document editable. Starts on Paid. --}}
                            <div class="pos-paystate" data-kind-only="invoice delivery_note" data-pay-state-group role="radiogroup" aria-label="{{ __('Payment status') }}">
                                <button type="button" class="pos-paystate__opt" data-pay-state="pending" role="radio" aria-checked="false">{{ __('Pending') }}</button>
                                <button type="button" class="pos-paystate__opt is-on" data-pay-state="paid" role="radio" aria-checked="true">{{ __('Paid') }}</button>
                            </div>
                            <button type="button" class="pos-ghost" data-customer-clear>
                                <span class="material-symbols-outlined icon icon--sm">backspace</span>
                                {{ __('Clear fields') }}
                            </button>
                        </div>

                        <div class="pos-billto__grid" data-customer-fields>
                            <div class="pos-field-suggest" data-customer-wrap>
                                <x-form.input
                                    name="walkin_phone"
                                    :label="__('Phone')"
                                    type="tel"
                                    required
                                    maxlength="30"
                                    autocomplete="off"
                                    :placeholder="__('Phone number')"
                                    data-pos-phone
                                    data-error-required="{{ __('Phone is required for a new customer.') }}"
                                    data-pattern="^[0-9+\-\s()]{7,30}$"
                                    data-error-pattern="{{ __('Enter a valid phone number.') }}"
                                    aria-autocomplete="list"
                                    aria-controls="pos-customer-suggest"
                                    aria-expanded="false"
                                />
                                <ul id="pos-customer-suggest" class="pos-suggest__list is-hidden" data-customer-suggest role="listbox" hidden></ul>
                            </div>
                            <x-form.input
                                name="walkin_name"
                                :label="__('Name')"
                                maxlength="150"
                                autocomplete="name"
                                :placeholder="__('Full name')"
                                data-pos-name
                                data-error-required="{{ __('Name is required for a new customer.') }}"
                            />
                            <x-form.input
                                name="walkin_email"
                                :label="__('Email')"
                                type="email"
                                maxlength="150"
                                autocomplete="email"
                                :placeholder="__('name@example.com')"
                                data-pos-email
                                data-error-email="{{ __('Enter a valid email address.') }}"
                            />
                            <x-form.input
                                name="walkin_nie"
                                :label="__('NIE / NIF')"
                                maxlength="20"
                                autocomplete="off"
                                placeholder="X1234567L"
                                data-pos-nie
                                data-pattern="^[A-Za-z0-9\-\s./]{5,20}$"
                                data-error-pattern="{{ __('Enter a valid NIE / NIF.') }}"
                            />
                            <div class="pos-billto__wide">
                                <x-form.input
                                    name="walkin_address"
                                    :label="__('Address')"
                                    maxlength="500"
                                    autocomplete="street-address"
                                    :placeholder="__('Street, number, postcode, city')"
                                    data-pos-address
                                />
                            </div>
                        </div>
                        <p class="pos-hint is-hidden" data-customer-locked>
                            <span class="material-symbols-outlined icon icon--xs">info</span>
                            {{ __('Name, email, NIE and address can be corrected here and are saved to their record. The number stays; use Clear fields to bill someone new.') }}
                        </p>
                    </section>

                    {{-- Scan adds immediately. Typing opens the catalog list. --}}
                    <section class="pos-scan" aria-label="{{ __('Add items') }}">
                        <div class="pos-section__head">
                            <span class="pos-secno">02</span>
                            <label class="pos-section__title" for="pos-scan">{{ __('Add items') }}</label>
                            <span class="pos-section__spacer"></span>
                            <p class="pos-keys">
                                <span>{{ __('Barcode adds instantly') }}</span>
                                <span class="pos-keys__gap">|</span>
                                <kbd class="pos-kbd">↑</kbd>
                                <kbd class="pos-kbd">↓</kbd>
                                <span>{{ __('move') }}</span>
                                <kbd class="pos-kbd">{{ __('Enter') }}</kbd>
                                <span>{{ __('add') }}</span>
                                <kbd class="pos-kbd">{{ __('Esc') }}</kbd>
                                <span>{{ __('close') }}</span>
                            </p>
                        </div>
                        <div class="pos-search pos-search--scan scan-input-wrap" data-scan-wrap>
                            <span class="pos-search__icon pos-search__icon--scan" aria-hidden="true">
                                <span class="material-symbols-outlined icon icon--md">barcode_scanner</span>
                            </span>
                            <input
                                id="pos-scan"
                                data-scan-input
                                class="input-field pos-search__input pos-search__input--scan"
                                placeholder="{{ __('Scan a barcode, or type an item code or name') }}"
                                aria-label="{{ __('Scan barcode or type a code or name') }}"
                                autocomplete="off"
                                aria-autocomplete="list"
                                aria-controls="pos-scan-suggest"
                                aria-expanded="false"
                            />
                            <span class="pos-scan__ready">
                                <span class="material-symbols-outlined icon icon--xs">sensors</span>
                                {{ __('Ready') }}
                            </span>
                            <ul id="pos-scan-suggest" class="pos-suggest__list is-hidden" data-scan-suggest role="listbox" hidden></ul>
                        </div>
                        <p data-scan-error class="form-error is-hidden"></p>
                        <button type="button" class="is-hidden" data-scan-submit tabindex="-1" aria-hidden="true">{{ __('Add') }}</button>
                    </section>

                    <section class="pos-items" aria-label="{{ __('Items') }}">
                        <div class="pos-section__head">
                            <span class="pos-secno">03</span>
                            <h2 class="pos-section__title">{{ __('Items') }}</h2>
                            <span class="pos-chip" data-preview-count-lines>{{ __('0 lines') }}</span>
                            <span class="pos-section__spacer"></span>
                            <button type="button" class="pos-ghost is-hidden" data-clear-lines>
                                <span class="material-symbols-outlined icon icon--sm">playlist_remove</span>
                                {{ __('Clear list') }}
                            </button>
                        </div>

                        <div class="pos-lines">
                            <div class="pos-lines__cols pos-cap">
                                <span>#</span>
                                <span>{{ __('Description') }}</span>
                                <span class="pos-lines__end">{{ __('Stock left') }}</span>
                                <span class="pos-lines__center">{{ __('Qty') }}</span>
                                <span class="pos-lines__end">{{ __('Weight') }}</span>
                                <span class="pos-lines__end">{{ __('Rate') }}</span>
                                <span class="pos-lines__end">{{ __('Making') }}</span>
                                <span class="pos-lines__end">{{ __('Amount') }}</span>
                                <span></span>
                            </div>
                            <div data-lines>
                                <div class="pos-lines__empty">
                                    <span class="pos-lines__empty-icon" aria-hidden="true">
                                        <span class="material-symbols-outlined icon icon--lg">barcode_scanner</span>
                                    </span>
                                    <p class="pos-lines__empty-title">{{ __('Scan or search to add the first line.') }}</p>
                                    <p class="pos-lines__empty-copy">{{ __('Jewellery adds as a single unique piece. Loose stones add by quantity.') }}</p>
                                </div>
                            </div>
                            <div class="pos-lines__foot is-hidden" data-lines-foot>
                                <span data-preview-items-note>{{ __('0 on this ticket') }}</span>
                                <span class="pos-lines__sum">
                                    <span class="pos-cap">{{ __('Lines total') }}</span>
                                    <span class="pos-num" data-preview-lines-total>{{ $currency }} 0.00</span>
                                </span>
                            </div>
                        </div>
                    </section>

                    <section class="pos-close">
                        <div class="pos-notes-block">
                            <div class="pos-section__head">
                                <span class="pos-secno">04</span>
                                <label class="pos-section__title" for="pos-notes">{{ __('Notes') }}</label>
                                <span class="pos-brand__meta">{{ __('Printed on the document') }}</span>
                            </div>
                            <textarea id="pos-notes" class="pos-notes" data-doc-notes rows="5" placeholder="{{ __('Sizing, collection date, stone certificates…') }}"></textarea>
                            <p class="pos-hint">
                                <span class="material-symbols-outlined icon icon--xs">shield_lock</span>
                                <span data-action-help>{{ __('A quotation holds stock until it is converted or cancelled.') }}</span>
                            </p>
                        </div>

                        <div class="pos-settle">
                            <div class="pos-totals">
                                <div class="pos-totals__row">
                                    <span>{{ __('Subtotal') }}</span>
                                    <span class="pos-num" data-preview-subtotal>0.00</span>
                                </div>

                                @if($canDiscount)
                                    <div class="pos-totals__adders" data-discount-off>
                                        <button type="button" class="pos-dashed" data-toggle-discount>
                                            <span class="material-symbols-outlined icon icon--xs">add</span>
                                            {{ __('Discount') }}
                                        </button>
                                    </div>
                                    <div class="pos-totals__row is-hidden" data-discount-fields>
                                        <span>{{ __('Discount') }}</span>
                                        <select class="pos-discount-type" data-no-select2 data-discount-type aria-label="{{ __('Discount type') }}">
                                            <option value="percentage">%</option>
                                            <option value="fixed">{{ $currency }}</option>
                                        </select>
                                        <input
                                            name="discount_value"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="pos-cell-input"
                                            placeholder="0"
                                            data-discount-value
                                            aria-label="{{ __('Discount value') }}"
                                        >
                                        <span class="pos-num pos-num--minus" data-preview-discount>0.00</span>
                                        <button type="button" class="pos-icon-btn" data-clear-discount aria-label="{{ __('Remove discount') }}">
                                            <span class="material-symbols-outlined icon icon--xs">close</span>
                                        </button>
                                    </div>
                                @endif

                                @if($taxEnabled ?? false)
                                    <div class="pos-totals__adders" data-tax-off>
                                        <button type="button" class="pos-dashed" data-toggle-tax>
                                            <span class="material-symbols-outlined icon icon--xs">add</span>
                                            {{ __('IVA') }}
                                        </button>
                                    </div>
                                    <div class="pos-totals__row is-hidden" data-tax-applied>
                                        <select class="pos-discount-type pos-tax-select" data-no-select2 data-iva-select aria-label="{{ __('IVA rate') }}">
                                            @foreach($ivaRates as $rate)
                                                <option value="{{ $rate['uuid'] }}" @selected($rate['uuid'] === $defaultIvaUuid)>{{ $rate['name'] }}</option>
                                            @endforeach
                                        </select>
                                        <span class="pos-num" data-preview-tax>0.00</span>
                                        <button type="button" class="pos-icon-btn" data-clear-tax aria-label="{{ __('Remove IVA') }}">
                                            <span class="material-symbols-outlined icon icon--xs">close</span>
                                        </button>
                                    </div>

                                    {{-- Recargo de equivalencia: offered once IVA is on. --}}
                                    @if(count($recargoRates))
                                        <div class="pos-totals__adders is-hidden" data-recargo-off>
                                            <button type="button" class="pos-dashed" data-toggle-recargo>
                                                <span class="material-symbols-outlined icon icon--xs">add</span>
                                                {{ __('Recargo de equivalencia') }}
                                            </button>
                                        </div>
                                        <div class="pos-totals__row is-hidden" data-recargo-applied>
                                            <select class="pos-discount-type pos-tax-select" data-no-select2 data-recargo-select aria-label="{{ __('Recargo de equivalencia rate') }}">
                                                @foreach($recargoRates as $rate)
                                                    <option value="{{ $rate['uuid'] }}">{{ $rate['name'] }}</option>
                                                @endforeach
                                            </select>
                                            <span class="pos-num" data-preview-recargo>0.00</span>
                                            <button type="button" class="pos-icon-btn" data-clear-recargo aria-label="{{ __('Remove recargo') }}">
                                                <span class="material-symbols-outlined icon icon--xs">close</span>
                                            </button>
                                        </div>
                                    @endif
                                @endif

                                <div class="pos-totals__grand">
                                    <div>
                                        <span class="pos-cap" data-preview-grand-label>{{ __('Quotation Total') }}</span>
                                        <p class="pos-totals__note" data-preview-total-note>{{ __('No discount or tax applied') }}</p>
                                    </div>
                                    <span class="pos-totals__figure" data-preview-grand>{{ $currency }} 0.00</span>
                                </div>
                            </div>

                            {{-- Only the button for the chosen document type is shown. --}}
                            @if($canConvert)
                                <x-btn type="button" block data-generate-invoice data-kind-only="invoice" :loading-text="__('Saving…')">
                                    <span class="material-symbols-outlined icon icon--sm">receipt_long</span>
                                    <span data-generate-label>{{ __('Generate Invoice & Deduct Stock') }}</span>
                                </x-btn>
                            @endif
                            <x-btn type="button" block data-generate-quotation data-kind-only="quotation" :loading-text="__('Saving…')">
                                <span class="material-symbols-outlined icon icon--sm">request_quote</span>
                                {{ __('Generate Quotation') }}
                            </x-btn>
                            @if($canDeliver)
                                <x-btn type="button" block data-generate-delivery-note data-kind-only="delivery_note" :loading-text="__('Saving…')">
                                    <span class="material-symbols-outlined icon icon--sm">local_shipping</span>
                                    <span data-generate-label>{{ __('Generate Delivery Note & Deduct Stock') }}</span>
                                </x-btn>
                            @endif
                            @if($canPerforma)
                                <x-btn type="button" block data-generate-performa data-kind-only="performa" :loading-text="__('Saving…')">
                                    <span class="material-symbols-outlined icon icon--sm">assignment</span>
                                    <span data-generate-performa-label>{{ __('Generate Performa & Deduct Stock') }}</span>
                                </x-btn>
                            @endif

                            <p class="pos-settle__note" data-kind-note></p>
                        </div>
                    </section>
                </div>
            </article>

            <p class="pos-stage__note">{{ __('Nothing leaves this screen until you generate. Saving prints the document and clears the ticket.') }}</p>
        </div>
    </main>
</div>

@if($canConvert || $canDeliver)
    <x-modal id="pos-invoice-modal" :title="__('Take payment')">
        <form id="pos-invoice-modal-form">
            <p class="form-help" data-pay-help>{{ __('Confirming creates the invoice and marks stock sold.') }}</p>
            <div class="pos-pay-due">
                <div>
                    <span class="pos-cap">{{ __('Grand Total') }}</span>
                    <p class="pos-pay-due__who" data-pay-customer>{{ __('Customer') }}</p>
                </div>
                <span class="pos-totals__figure" data-pay-total>{{ $currency }} 0.00</span>
            </div>
            <div data-field class="pos-pay-field">
                <p class="pos-cap">{{ __('Payment method') }} <span class="form-label__required">*</span></p>
                <select
                    name="payment_method"
                    class="pos-pay-native"
                    required
                    data-no-select2
                    data-error-required="{{ __('Choose how the customer is paying.') }}"
                    aria-label="{{ __('Payment method') }}"
                >
                    <option value=""></option>
                    @foreach($paymentMethods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <div class="pos-pay-grid">
                    @foreach($paymentMethods as $value => $label)
                        <button type="button" class="pos-pay" data-pay-method="{{ $value }}" aria-pressed="false">
                            <span class="pos-thumb">
                                <span class="material-symbols-outlined icon icon--sm">{{ $payIcons[$value] ?? 'payments' }}</span>
                            </span>
                            <span>{{ $label }}</span>
                        </button>
                    @endforeach
                </div>
                <p data-error-for="payment_method" class="form-error is-hidden"></p>
            </div>
            <x-form.input name="payment_reference" :label="__('Reference')" maxlength="100" :placeholder="__('Card slip, transfer or cheque number')" />
            <div class="form-actions">
                <x-btn type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-btn>
                <x-btn type="submit" :loading-text="__('Saving…')">
                    <span class="material-symbols-outlined icon icon--sm">print</span>
                    <span data-pay-confirm>{{ __('Confirm & create invoice') }}</span>
                </x-btn>
            </div>
        </form>
    </x-modal>
@endif
@endsection
