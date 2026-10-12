{{-- Branch read-only gold rates view. --}}
@extends('layouts.branch')

@section('title', 'Gold Rates')
@section('page-title', 'Gold Rates')

@section('content')
<x-page-header title="Gold Rates" subtitle="Current global gold rates per purity." />

@include('shared.gold-rates._content')
@endsection
