{{-- Browser tab icon: uploaded favicon when set, otherwise the bundled mark. --}}
@php
    $favicon = brand_asset_url('company_favicon');
@endphp
@if($favicon)
    <link rel="icon" href="{{ $favicon }}">
    <link rel="shortcut icon" href="{{ $favicon }}">
    <link rel="apple-touch-icon" href="{{ $favicon }}">
@else
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32.png') }}" type="image/png" sizes="32x32">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('favicon-32.png') }}">
@endif
