{{-- SuperAdmin gold rate management. Design: global_settings gold rates card. --}}
@extends('layouts.superadmin')

@section('title', __('Gold Rates'))
@section('page-title', __('Gold Rates'))

@section('content')
<x-page-header :title="__('Gold Rates')" :subtitle="__('Set global rates per purity. History is preserved on every change.')" />

@include('shared.gold-rates._content')
@endsection
