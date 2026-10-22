{{-- Public invoice verification — design: public_invoice_verification. --}}
@extends('layouts.public')

@section('title', __('Invoice Verification'))

@section('content')
<div class="panel verify-card">
    <p class="verify-card__brand">{{ setting('company_name', 'Aani Gemas') }}</p>

    @if($payload['voided'] ?? false)
        <x-status-badge status="voided" :label="__('Invoice voided')" />
        <p class="verify-card__number">{{ $payload['invoice_number'] }}</p>
        <p class="verify-card__message">
            {{ __('This invoice, dated :date, is no longer valid.', ['date' => $payload['invoice_date']]) }}
        </p>
    @else
        <x-status-badge status="active" :label="__('Verified Invoice')" />

        <p class="verify-card__number">{{ $payload['invoice_number'] }}</p>
        <p class="verify-card__meta">{{ $payload['invoice_date'] }} · {{ $payload['branch_name'] }}</p>
        <p class="verify-card__customer">{{ $payload['customer_name'] }}</p>

        <ul class="verify-card__items">
            @foreach($payload['items'] as $item)
                <li class="verify-card__item">
                    <span>
                        {{ $item['description'] }}
                        @if($item['weight'])
                            <span class="verify-card__item-weight">({{ $item['weight'] }})</span>
                        @endif
                    </span>
                    <span class="verify-card__item-total">
                        {{ setting_money($item['line_total']) }}
                    </span>
                </li>
            @endforeach
        </ul>

        <p class="verify-card__total">
            {{ setting_money($payload['grand_total']) }}
        </p>
    @endif
</div>
@endsection
