{{-- Branch read-only gold rates view. --}}
@extends('layouts.branch')

@section('title', __('Gold Rates'))
@section('page-title', __('Gold Rates'))

@section('content')
<x-page-header :title="__('Gold Rates')" :subtitle="__('Current global gold rates per purity.')" />

@include('shared.gold-rates._content')
@endsection
