{{--
    Performa settlement — outstanding lines with the quotation calculator
    (weight, gold rate, making, stones). Tax follows SuperAdmin settings.
--}}
@extends('layouts.branch')

@section('title', __('Settle').' '.$performa->performa_number)
@section('page-title', __('Settle Performa'))

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
        'ivaRates' => $ivaRates,
        'recargoRates' => $recargoRates,
        'defaultIvaUuid' => $defaultIvaUuid,
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
                    <h1 class="sales-workspace__title">{{ __('Settle') }} {{ $performa->performa_number }}</h1>
                    <p class="sales-workspace__meta">{{ $performa->displayCreditorName() }} · {{ __('net weight × rate + making + stones') }}</p>
                </div>
                <div class="flex gap-2">
                    <x-btn type="button" variant="secondary" data-settle-remaining>{{ __('Settle remaining') }}</x-btn>
                    <x-btn href="{{ route('branch.performas.show', $performa) }}" variant="secondary">{{ __('Back') }}</x-btn>
                </div>
            </div>

            <div class="sales-workspace__list">
                <div data-settle-lines class="sale-lines"></div>
            </div>
        </div>

        <aside class="sales-workspace__aside">
            <section class="sales-aside__section">
                <p class="sales-aside__label">{{ __('This payment') }}</p>
                <dl class="totals">
                    <div class="totals__row">
                        <dt class="totals__label">{{ __('Gold value') }} <span data-gold-weight></span></dt>
                        <dd class="totals__value" data-gold-value>0.00</dd>
                    </div>
                    <div class="totals__row">
                        <dt class="totals__label">{{ __('Stone value') }}</dt>
                        <dd class="totals__value" data-stone-value>0.00</dd>
                    </div>
                    <div class="totals__row totals__row--rule">
                        <dt class="totals__label">{{ __('Making charges') }}</dt>
                        <dd class="totals__value" data-making-value>0.00</dd>
                    </div>
                    <div class="totals__row">
                        <dt class="totals__label">{{ __('Subtotal') }}</dt>
                        <dd class="totals__value" data-subtotal>0.00</dd>
                    </div>
                    @if($taxEnabled)
                        <div class="totals__row totals__row--rule">
                            <dt class="totals__label" data-tax-label>{{ __('IVA') }}</dt>
                            <dd class="totals__value" data-tax-amount>0.00</dd>
                        </div>
                        @if(count($recargoRates))
                            <div class="totals__row">
                                <dt class="totals__label" data-recargo-label>{{ __('Recargo de equivalencia') }}</dt>
                                <dd class="totals__value" data-recargo-amount>0.00</dd>
                            </div>
                        @endif
                    @endif
                    <div class="totals__row totals__row--grand">
                        <dt class="totals__label">{{ __('Payment total') }}</dt>
                        <dd class="totals__value" data-settle-total>0.00</dd>
                    </div>
                </dl>
                @if($taxEnabled)
                    <div class="form-grid mt-3">
                        <div>
                            <label class="form-label" for="settle_tax_rate_uuid">{{ __('IVA') }}</label>
                            <select id="settle_tax_rate_uuid" class="input-field" data-no-select2 data-settle-iva>
                                <option value="">{{ __('No IVA') }}</option>
                                @foreach($ivaRates as $rate)
                                    <option value="{{ $rate['uuid'] }}" @selected($rate['uuid'] === $defaultIvaUuid)>{{ $rate['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if(count($recargoRates))
                            <div>
                                <label class="form-label" for="settle_recargo_rate_uuid">{{ __('Recargo de equivalencia') }}</label>
                                <select id="settle_recargo_rate_uuid" class="input-field" data-no-select2 data-settle-recargo>
                                    <option value="">{{ __('None') }}</option>
                                    @foreach($recargoRates as $rate)
                                        <option value="{{ $rate['uuid'] }}">{{ $rate['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                @endif
            </section>

            <section class="sales-aside__section sales-aside__section--checkout">
                <div class="sales-aside__actions">
                    <x-btn type="button" class="btn--tall" data-modal-open="settle-pay-modal" block>
                        <span class="material-symbols-outlined icon icon--sm">point_of_sale</span>
                        {{ __('Take payment') }}
                    </x-btn>
                </div>
            </section>
        </aside>
    </form>
</div>

<x-modal id="settle-pay-modal" :title="__('Record payment')">
    <form id="settle-pay-form">
        <div class="form-grid">
            <x-form.select
                name="payment_method"
                :label="__('Payment method')"
                :options="$paymentMethods"
                required
                data-error-required="{{ __('Select a payment method.') }}"
            />
            <x-form.input name="payment_reference" :label="__('Reference (optional)')" maxlength="100" />
        </div>
        <div class="form-actions">
            <x-btn type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-btn>
            <x-btn type="submit" :loading-text="__('Saving…')">{{ __('Create invoice') }}</x-btn>
        </div>
    </form>
</x-modal>
@endsection
