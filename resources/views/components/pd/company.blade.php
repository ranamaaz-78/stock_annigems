{{--
    Company name and contact lines for the top of a document: address, city,
    NIF/CIF (when the company has one in settings), then email and phone.
--}}
@props(['branch' => null])

@php
    $lines = array_values(array_filter([
        $branch?->address,
        $branch?->city,
        filled(setting('company_tax_id')) ? 'NIF/CIF: '.setting('company_tax_id') : null,
        collect([$branch?->email, $branch?->contact_number])->filter()->implode(' · ') ?: null,
    ]));
@endphp

<div class="pd__company">
    <p class="pd__company-name">{{ setting('company_name', config('annigems.documents.brand_name')) }}</p>
    @if($lines !== [])
        <p class="pd__company-lines">
            @foreach($lines as $line)<span>{{ $line }}</span>@endforeach
        </p>
    @endif
</div>
