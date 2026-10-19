{{--
    Tax rates: the IVA list and the Recargo de equivalencia list.

    Nothing is fixed in code. A cashier picks one IVA rate (and, if it applies,
    one Recargo) on a document; the document keeps a snapshot, so changing or
    deleting a rate here never touches an invoice already made.
--}}
@extends('layouts.superadmin')

@section('title', __('Tax Rates'))
@section('page-title', __('Tax Rates'))

@php
    $panels = [
        ['kind' => 'iva', 'title' => __('IVA'), 'rates' => $iva, 'hint' => __('One rate per document, charged on the discounted total. The default is preselected on the POS.'), 'placeholder' => 'IVA 21%'],
        ['kind' => 'recargo', 'title' => __('Recargo de equivalencia'), 'rates' => $recargo, 'hint' => __('Optional surcharge added next to IVA, on the same discounted total.'), 'placeholder' => 'Recargo 5.2%'],
    ];
@endphp

@section('content')
<x-page-header :title="__('Tax Rates')" :subtitle="__('IVA and Recargo de equivalencia. Cashiers choose from these lists; no rate is fixed in the system.')" />

@unless($taxEnabled)
    <x-alert type="warning">
        {{ __('Tax is switched off, so these rates are not applied to new documents. Turn it on under Global Settings → Tax.') }}
    </x-alert>
@endunless

@if($errors->any())
    <x-alert type="error">{{ $errors->first() }}</x-alert>
@endif

<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
    @foreach($panels as $panel)
        <section class="panel" aria-labelledby="panel-{{ $panel['kind'] }}">
            <div class="panel__body">
                <h2 id="panel-{{ $panel['kind'] }}" class="text-headline-sm">{{ $panel['title'] }}</h2>
                <p class="form-help mb-3">{{ $panel['hint'] }}</p>

                <form method="POST" action="{{ route('superadmin.tax-rates.store') }}" class="form-grid mb-4" data-loading-form>
                    @csrf
                    <input type="hidden" name="kind" value="{{ $panel['kind'] }}">
                    <x-form.input name="name" :label="__('Name')" :value="''" maxlength="60" required :placeholder="$panel['placeholder']" />
                    <x-form.input name="rate" :label="__('Percentage')" type="number" step="0.001" min="0" max="100" :placeholder="'21'" />
                    @if($panel['kind'] === 'iva')
                        <label class="form-label flex items-center gap-2">
                            <input type="checkbox" name="is_exempt" value="1">
                            {{ __('Exempt (no percentage)') }}
                        </label>
                    @endif
                    <div class="form-actions">
                        <x-btn type="submit" :loading-text="__('Adding…')">{{ __('Add :name rate', ['name' => $panel['title']]) }}</x-btn>
                    </div>
                </form>

                <div class="panel__scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th class="table-admin__num">{{ __('Rate') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($panel['rates'] as $rate)
                                <tr>
                                    <td>
                                        <strong>{{ $rate->name }}</strong>
                                        @if($rate->is_default)<span class="badge badge--completed">{{ __('Default') }}</span>@endif
                                        @if($rate->is_exempt)<span class="badge badge--inactive">{{ __('Exempt') }}</span>@endif
                                    </td>
                                    <td class="table-admin__num">{{ $rate->is_exempt ? '—' : $rate->rateLabel() }}</td>
                                    <td><x-status-badge :status="$rate->is_active" /></td>
                                    <td class="table-admin__actions-col">
                                        <details class="inline-block">
                                            <summary class="form-link text-body-sm">{{ __('Edit') }}</summary>
                                            <form method="POST" action="{{ route('superadmin.tax-rates.update', $rate) }}" class="form-grid mt-2" data-loading-form>
                                                @csrf
                                                @method('PUT')
                                                <x-form.input name="name" :label="__('Name')" :value="$rate->name" maxlength="60" required />
                                                <x-form.input name="rate" :label="__('Percentage')" type="number" step="0.001" min="0" max="100" :value="rtrim(rtrim(number_format((float) $rate->rate, 3, '.', ''), '0'), '.')" />
                                                @if($rate->kind === 'iva')
                                                    <label class="form-label flex items-center gap-2">
                                                        <input type="checkbox" name="is_exempt" value="1" @checked($rate->is_exempt)>
                                                        {{ __('Exempt') }}
                                                    </label>
                                                @endif
                                                <x-btn type="submit" variant="secondary" :loading-text="__('Saving…')">{{ __('Save') }}</x-btn>
                                            </form>
                                        </details>
                                        @if($rate->kind === 'iva' && $rate->is_active && ! $rate->is_default)
                                            <form method="POST" action="{{ route('superadmin.tax-rates.default', $rate) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="form-link text-body-sm">{{ __('Make default') }}</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('superadmin.tax-rates.toggle', $rate) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="form-link text-body-sm">{{ $rate->is_active ? __('Turn off') : __('Turn on') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('superadmin.tax-rates.destroy', $rate) }}" class="inline" onsubmit="return confirm(@js(__('Delete :name? Documents already made keep it.', ['name' => $rate->name])))">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="form-link text-body-sm">{{ __('Delete') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-body-sm text-on-surface-variant">{{ __('No rates yet. Add one above.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endforeach
</div>
@endsection