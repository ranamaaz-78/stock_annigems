{{--
    Performa settlement — outstanding lines with the quotation calculator
    (weight, gold rate, making, stones). Tax follows SuperAdmin settings.
--}}
@extends('layouts.branch')

@section('title', 'Settle '.$performa->performa_number)
@section('page-title', 'Settle Performa')

@php
    $settleConfig = [
        'lines' => $lines,
        'rateMap' => $rateMap,
        'currencySymbol' => $currencySymbol,
        'currencyDecimals' => $currencyDecimals,
        'taxEnabled' => $taxEnabled,
        'taxType' => $taxType,
        'taxRate' => $taxRate,
        'taxLabel' => $taxLabel,
        'routes' => [
            'settle' => route('branch.performas.settle.store', $performa),
            'invoiceShow' => route('branch.invoices.show', ['invoice' => '__UUID__']),
        ],
    ];
@endphp

@section('content')
<div
    id="performa-settle-root"
    data-config="{{ json_encode($settleConfig) }}"
>
    <form data-settle-form class="sales-workspace">
        <div class="sales-workspace__main">
            <div class="sales-workspace__toolbar">
                <div class="sales-workspace__heading">
                    <h1 class="sales-workspace__title">Settle {{ $performa->performa_number }}</h1>
                    <p class="sales-workspace__meta">{{ $performa->displayCreditorName() }} · net weight × rate + making + stones</p>
                </div>
                <div class="flex gap-2">
                    <x-btn type="button" variant="secondary" data-settle-remaining>Settle remaining</x-btn>
                    <x-btn href="{{ route('branch.performas.show', $performa) }}" variant="secondary">Back</x-btn>
                </div>
            </div>

            <div class="sales-workspace__list">
                <div data-settle-lines class="sale-lines"></div>
            </div>
        </div>

        <aside class="sales-workspace__aside">
            <section class="sales-aside__section">
                <p class="sales-aside__label">This payment</p>
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
                    @if($taxEnabled)
                        <div class="totals__row totals__row--rule">
                            <dt class="totals__label">{{ $taxLabel }}</dt>
                            <dd class="totals__value" data-tax-amount>0.00</dd>
                        </div>
                    @endif
                    <div class="totals__row totals__row--grand">
                        <dt class="totals__label">Payment total</dt>
                        <dd class="totals__value" data-settle-total>0.00</dd>
                    </div>
                </dl>
                @if($taxEnabled)
                    <p class="form-help">
                        {{ $taxLabel }}
                        @if($taxType === 'percentage')
                            {{ rtrim(rtrim(number_format((float) $taxRate, 2), '0'), '.') }}%
                        @else
                            {{ setting_money($taxRate) }}
                        @endif
                        — set by SuperAdmin.
                    </p>
                @endif
            </section>

            <section class="sales-aside__section sales-aside__section--checkout">
                <div class="sales-aside__actions">
                    <x-btn type="button" class="btn--tall" data-modal-open="settle-pay-modal" block>
                        <span class="material-symbols-outlined icon icon--sm">point_of_sale</span>
                        Take payment
                    </x-btn>
                </div>
            </section>
        </aside>
    </form>
</div>

<x-modal id="settle-pay-modal" title="Record payment">
    <form id="settle-pay-form">
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
@endsection
