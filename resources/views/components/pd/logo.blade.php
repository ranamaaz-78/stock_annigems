{{-- Company logo (uploaded) or the single-letter mark tile. --}}
@props(['large' => false])

@if(brand_asset_url('company_logo'))
    <img class="pd__logo" src="{{ brand_asset_url('company_logo') }}" alt="{{ setting('company_name') }}">
@else
    <span class="pd__mark" aria-hidden="true">{{ config('annigems.documents.brand_mark') }}</span>
@endif
