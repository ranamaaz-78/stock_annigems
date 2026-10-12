{{--
    Branch dashboard.

    Every card, list and chart on this page is decided server-side by
    DashboardStatsService from the actor's role and permissions, so this file
    renders whatever it is handed and makes no visibility decision of its own.
    A card the user may not see is never built, let alone hidden here.

    Figures are revenue, weight and count only. No valuation and no profit
    appears anywhere, because the system holds no purchase cost.
--}}
@extends('layouts.branch')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@php
    use App\Support\Reports\ReportFormatter;
@endphp

@section('content')
<x-page-header
    title="Dashboard"
    :subtitle="$dashboard['branch_name'].' · '.$dashboard['role_label']"
>
    <x-slot:actions>
        @can('create', \App\Models\Quotation::class)
            <x-btn href="{{ route('branch.pos.show') }}">
                <span class="material-symbols-outlined icon icon--sm">point_of_sale</span>
                Official Invoicing / POS
            </x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

@if(empty($dashboard['cards']) && empty($dashboard['secondary_cards']))
    <x-alert type="info" class="mb-4">
        Your role does not have dashboard summary permissions yet. Contact your branch Admin if you need access.
    </x-alert>
@endif

{{-- Primary cards: the headline figures for this role. --}}
@if(! empty($dashboard['cards']))
    <div class="kpi-grid mb-4">
        @foreach($dashboard['cards'] as $card)
            <x-report.kpi
                :label="$card['label']"
                :value="$card['value']"
                :hint="$card['hint']"
                :icon="$card['icon']"
                :trend="$card['trend']"
                trend-caption="vs last month"
            />
        @endforeach
    </div>
@endif

{{-- Secondary cards: supporting stock and pipeline figures. --}}
@if(! empty($dashboard['secondary_cards']))
    <div class="kpi-grid mb-6">
        @foreach($dashboard['secondary_cards'] as $card)
            <x-report.kpi
                :label="$card['label']"
                :value="$card['value']"
                :hint="$card['hint']"
                :icon="$card['icon']"
                :trend="$card['trend']"
            />
        @endforeach
    </div>
@endif

@if($dashboard['chart'])
    <div class="mb-6">
        <x-report.chart :chart="$dashboard['chart']" />
    </div>
@endif

{{-- Quotation funnel: a compact conversion read-out for Admin and Manager. --}}
@if($dashboard['quotation_funnel'])
    <div class="panel mb-6">
        <div class="panel-header">
            <h2 class="panel-header__title text-headline-sm">Quotation Conversion — This Month</h2>
            @if($dashboard['can_view_reports'])
                <a href="{{ route('branch.reports.show', 'quotation-funnel') }}" class="form-link">Open report</a>
            @endif
        </div>
        <div class="p-4">
            <dl class="detail-list">
                <div class="detail-list__item">
                    <dt class="detail-list__term">Quotations Raised</dt>
                    <dd class="detail-list__value">{{ ReportFormatter::count($dashboard['quotation_funnel']['total']) }}</dd>
                </div>
                <div class="detail-list__item">
                    <dt class="detail-list__term">Converted</dt>
                    <dd class="detail-list__value">{{ ReportFormatter::count($dashboard['quotation_funnel']['converted']) }}</dd>
                </div>
                <div class="detail-list__item">
                    <dt class="detail-list__term">Conversion Rate</dt>
                    <dd class="detail-list__value">{{ ReportFormatter::percent($dashboard['quotation_funnel']['conversion_rate']) }}</dd>
                </div>
                <div class="detail-list__item">
                    <dt class="detail-list__term">Converted Value</dt>
                    <dd class="detail-list__value">{{ ReportFormatter::money($dashboard['quotation_funnel']['converted_value']) }}</dd>
                </div>
            </dl>
        </div>
    </div>
@endif

<div class="report-split">
    {{-- Recent invoices. Rows open the invoice itself, which every listed role
         may already reach from the Invoices screen. --}}
    @if($dashboard['recent_invoices']->isNotEmpty())
        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-header__title text-headline-sm">Recent Invoices</h2>
                <a href="{{ route('branch.invoices.index') }}" class="form-link">View all</a>
            </div>
            <div class="table-scroll">
                <table class="table-admin table-admin--dense">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th class="table-admin__num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dashboard['recent_invoices'] as $invoice)
                            <tr>
                                <td class="text-data-mono">
                                    <a href="{{ route('branch.invoices.show', $invoice['uuid']) }}" class="form-link">{{ $invoice['invoice_number'] }}</a>
                                </td>
                                <td>{{ $invoice['customer'] }}</td>
                                <td class="text-data-mono">{{ $invoice['invoice_date'] }}</td>
                                <td class="table-admin__num">{{ ReportFormatter::money($invoice['grand_total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Pending quotations, oldest first: an old draft carries a stale rate. --}}
    @if($dashboard['pending_quotations']->isNotEmpty())
        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-header__title text-headline-sm">Oldest Pending Quotations</h2>
                <a href="{{ route('branch.quotations.index') }}" class="form-link">View all</a>
            </div>
            <div class="table-scroll">
                <table class="table-admin table-admin--dense">
                    <thead>
                        <tr>
                            <th>Quotation</th>
                            <th>Customer</th>
                            <th class="table-admin__num">Age</th>
                            <th class="table-admin__num">Quoted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dashboard['pending_quotations'] as $quotation)
                            <tr>
                                <td class="text-data-mono">
                                    <a href="{{ route('branch.quotations.show', $quotation['uuid']) }}" class="form-link">{{ $quotation['quotation_number'] }}</a>
                                </td>
                                <td>{{ $quotation['customer'] }}</td>
                                <td class="table-admin__num">{{ $quotation['age_days'] }}d</td>
                                <td class="table-admin__num">{{ ReportFormatter::money($quotation['value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Stones needing a reorder decision. --}}
    @if($dashboard['low_stones']->isNotEmpty())
        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-header__title text-headline-sm">Zero &amp; Low Stone Stock</h2>
                <a href="{{ route('branch.stones.index') }}" class="form-link">View all</a>
            </div>
            <div class="table-scroll">
                <table class="table-admin table-admin--dense">
                    <thead>
                        <tr>
                            <th>Stone</th>
                            <th>Shape</th>
                            <th class="table-admin__num">Qty</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dashboard['low_stones'] as $stone)
                            <tr>
                                <td class="text-data-mono">
                                    <a href="{{ route('branch.stones.show', $stone['uuid']) }}" class="form-link">{{ $stone['stone_code'] }}</a>
                                </td>
                                <td>{{ $stone['shape'] }}</td>
                                <td class="table-admin__num {{ $stone['quantity'] === 0 ? 'table-admin__num--negative' : '' }}">{{ $stone['quantity'] }}</td>
                                <td>{{ $stone['severity'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Pieces sitting past the oldest configured aging edge, oldest first. --}}
    @if($dashboard['aging_items']->isNotEmpty())
        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-header__title text-headline-sm">Longest-Held Stock</h2>
                <a href="{{ route('branch.jewellery-items.index') }}" class="form-link">View all</a>
            </div>
            <div class="table-scroll">
                <table class="table-admin table-admin--dense">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Item</th>
                            <th class="table-admin__num">Held</th>
                            <th class="table-admin__num">Net Wt</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dashboard['aging_items'] as $item)
                            <tr>
                                <td class="text-data-mono">
                                    <a href="{{ route('branch.jewellery-items.show', $item['uuid']) }}" class="form-link">{{ $item['item_code'] }}</a>
                                </td>
                                <td>{{ $item['name'] }}</td>
                                <td class="table-admin__num">{{ $item['days_held'] }}d</td>
                                <td class="table-admin__num">{{ ReportFormatter::grams($item['net_weight']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Inventory Staff only: what has recently come in. --}}
    @if($dashboard['recent_intakes']->isNotEmpty())
        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-header__title text-headline-sm">Recent Stone Intakes</h2>
                <a href="{{ route('branch.stone-intakes.index') }}" class="form-link">View all</a>
            </div>
            <div class="table-scroll">
                <table class="table-admin table-admin--dense">
                    <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Date</th>
                            <th class="table-admin__num">Qty</th>
                            <th class="table-admin__num">Carat</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dashboard['recent_intakes'] as $intake)
                            <tr>
                                <td class="text-data-mono">
                                    <a href="{{ route('branch.stone-intakes.show', $intake['uuid']) }}" class="form-link">{{ $intake['intake_number'] }}</a>
                                </td>
                                <td class="text-data-mono">{{ $intake['intake_date'] }}</td>
                                <td class="table-admin__num">{{ $intake['quantity'] }}</td>
                                <td class="table-admin__num">{{ ReportFormatter::carats($intake['total_carat']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
