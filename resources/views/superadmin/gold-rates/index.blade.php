{{-- SuperAdmin gold rate management. Design: global_settings gold rates card. --}}
@extends('layouts.superadmin')

@section('title', 'Gold Rates')
@section('page-title', 'Gold Rates')

@section('content')
<x-page-header title="Gold Rates" subtitle="Set global rates per purity. History is preserved on every change." />

@include('shared.gold-rates._content')
@endsection
