{{--
    Performa issue workspace — add goods by scan, short code, or name,
    attach a registered creditor, no prices. Stock leaves on save.
--}}
@extends('layouts.branch')

@section('title', 'New Performa')
@section('page-title', 'New Performa')

@php
    $formConfig = [
        'payload' => $payload,
        'routes' => [
            'lookup' => route('branch.performas.lookup'),
            'search' => route('branch.performas.search'),
            'store' => route('branch.performas.store'),
            'show' => route('branch.performas.show', ['performa' => '__UUID__']),
            'creditorSearch' => route('branch.creditors.search'),
            'creditorCreate' => route('branch.creditors.create'),
        ],
    ];
    $selectedCreditor = $payload['creditor'] ?? null;
@endphp

@section('content')
<div
    id="performa-form-root"
    data-config="{{ json_encode($formConfig) }}"
    data-scanner="{{ json_encode($scanner) }}"
>
    <form data-save-form class="sales-workspace">
        <div class="sales-workspace__main">
            <div class="sales-workspace__toolbar">
                <div class="sales-workspace__heading">
                    <h1 class="sales-workspace__title">New Performa</h1>
                    <p class="sales-workspace__meta" data-item-count>No items scanned</p>
                </div>
                <x-btn href="{{ route('branch.performas.index') }}" variant="secondary">Back</x-btn>
            </div>

            <div class="scan-dock">
                <label class="form-label" for="performa-scan">Scan barcode or enter SKU</label>
                <div class="scan-dock__row scan-input-wrap" data-scan-wrap>
                    <span class="scan-dock__icon" aria-hidden="true">
                        <span class="material-symbols-outlined icon icon--md icon--secondary">barcode_scanner</span>
                    </span>
                    <input
                        id="performa-scan"
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
                        <label class="form-label" for="performa-short-code">Short code</label>
                        <input
                            id="performa-short-code"
                            type="text"
                            class="input-field"
                            maxlength="3"
                            autocomplete="off"
                            placeholder="DIA"
                            data-short-code-input
                        >
                    </div>
                    <div>
                        <label class="form-label" for="performa-item-search">Search by name</label>
                        <select
                            id="performa-item-search"
                            class="input-field"
                            data-item-search
                            data-ajax-url="{{ route('branch.performas.search') }}"
                            data-ajax-collection="items"
                        >
                            <option value="">Jewellery or stone name…</option>
                        </select>
                    </div>
                </div>
                <p data-scan-error class="form-error is-hidden"></p>
            </div>

            <div class="sales-workspace__list">
                <div data-lines-empty class="sales-workspace__empty">
                    <x-empty-state
                        title="Add the goods they are taking"
                        message="Scan a tag, type a 3-letter short code, or search by name. No prices here — stock leaves when you issue."
                        icon="barcode_scanner"
                    />
                </div>
                <div data-lines class="sale-lines"></div>
            </div>
        </div>

        <aside class="sales-workspace__aside">
            <section class="sales-aside__section">
                <div class="customer-picker">
                    <div class="sales-aside__bar">
                        <p class="sales-aside__label">Creditor</p>
                        @can('create', \App\Models\Creditor::class)
                            <a href="{{ route('branch.creditors.create') }}" class="form-link">Add creditor</a>
                        @endcan
                    </div>

                    <div data-creditor-select-wrap>
                        <label for="performa-creditor" class="form-label">Issued against</label>
                        <select
                            id="performa-creditor"
                            name="creditor_uuid"
                            class="input-field"
                            data-creditor-select
                            data-ajax-url="{{ route('branch.creditors.search') }}"
                            data-ajax-collection="creditors"
                            data-error-required="Select a creditor."
                            required
                        >
                            <option value="">Search by name or phone…</option>
                            @if($selectedCreditor)
                                <option value="{{ $selectedCreditor['uuid'] }}" selected>
                                    {{ $selectedCreditor['name'] }} · {{ $selectedCreditor['phone'] }}
                                </option>
                            @endif
                        </select>
                        <p data-error-for="creditor_uuid" class="form-error is-hidden"></p>
                    </div>

                    <div class="field-inline">
                        <label class="field-inline__label" for="performa-date">Performa date</label>
                        <input
                            id="performa-date"
                            type="date"
                            data-doc="performa_date"
                            class="input-field"
                            value="{{ $payload['performa_date'] ?? now()->toDateString() }}"
                        />
                    </div>
                </div>
            </section>

            <section class="sales-aside__section sales-aside__section--checkout">
                <div class="sales-aside__notes">
                    <label class="field-inline__label" for="performa-notes">Notes</label>
                    <textarea id="performa-notes" data-doc="notes" class="input-field" rows="2"></textarea>
                </div>
                <div class="sales-aside__actions">
                    <x-btn type="submit" class="btn--tall" loading-text="Issuing…" block>
                        <span class="material-symbols-outlined icon icon--sm">assignment</span>
                        Issue performa
                    </x-btn>
                </div>
            </section>
        </aside>
    </form>
</div>
@endsection
