{{--
    SuperAdmin dashboard.

    Aggregate figures only. There is no invoice, quotation, customer, item or
    stone on this page and no row links to one — spec 7.4 forbids SuperAdmin
    drilling through to branch records, which is why the per-row visibility
    action from the approved mockup is deliberately absent from the comparison
    table below.

    The "Total Stock Value" card in the mockup is replaced by Gold Weight in
    Stock: no purchase cost is recorded anywhere in the system, so a valuation
    would have to be invented.
--}}
@extends('layouts.superadmin')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@php
    use App\Support\Reports\ReportFormatter;
@endphp

@section('content')
<x-page-header
    title="Dashboard"
    :subtitle="$dashboard['branch_name'].' · aggregate figures only'"
/>

{{-- Scope selector. Choosing a branch narrows the figures; it unlocks no
     additional detail, which is the whole point of spec 7.3. --}}
<form method="GET" action="{{ route('superadmin.dashboard') }}" class="filter-bar mb-4">
    <div>
        <label for="branch" class="form-label">Scope</label>
        <select id="branch" name="branch" class="input-field" onchange="this.form.submit()">
            <option value="">All Branches</option>
            @foreach($branches as $branch)
                <option value="{{ $branch->uuid }}" @selected($selectedBranch === $branch->uuid)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    {{-- Submit button for keyboard users and browsers without JS. --}}
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">Apply</x-btn>
    </div>
</form>

<div class="kpi-grid mb-6">
    @foreach($dashboard['cards'] as $card)
        <x-report.kpi
            :label="$card['label']"
            :value="$card['value']"
            :hint="$card['hint']"
            :icon="$card['icon']"
            :trend="$card['trend']"
        />
    @endforeach
</div>

@if($dashboard['chart'])
    <div class="mb-6">
        <x-report.chart :chart="$dashboard['chart']" />
    </div>
@endif

@if($dashboard['mode'] === 'all')
    <div class="panel">
        <div class="panel-header">
            <h2 class="panel-header__title text-headline-sm">Branch Comparison — This Month</h2>
            <a href="{{ route('superadmin.branches.index') }}" class="form-link">Manage branches</a>
        </div>

        @if($dashboard['comparison']->isEmpty())
            <div class="panel-body">
                <x-empty-state title="No branches yet" message="Create your first branch to start assigning Branch Admins." icon="store">
                    <x-slot:actions>
                        <x-btn href="{{ route('superadmin.branches.create') }}">+ Add Branch</x-btn>
                    </x-slot:actions>
                </x-empty-state>
            </div>
        @else
            <div class="table-scroll">
                <table class="table-admin table-admin--dense">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Status</th>
                            <th class="table-admin__num">Revenue</th>
                            <th class="table-admin__num">Invoices</th>
                            <th class="table-admin__num">Pieces Sold</th>
                            <th class="table-admin__num">Pieces in Stock</th>
                            <th class="table-admin__num">Gold in Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dashboard['comparison'] as $row)
                            <tr>
                                <td>
                                    {{-- Three-letter mark plus name, per the approved comparison table. --}}
                                    <span class="branch-cell">
                                        <span class="branch-cell__mark">{{ \Illuminate\Support\Str::substr($row['code'] ?: $row['branch'], 0, 3) }}</span>
                                        <span class="branch-cell__name">{{ $row['branch'] }}</span>
                                    </span>
                                </td>
                                <td><x-status-badge :status="$row['status']" /></td>
                                <td class="table-admin__num table-admin__num--strong">{{ ReportFormatter::money($row['revenue']) }}</td>
                                <td class="table-admin__num">{{ ReportFormatter::count($row['invoices']) }}</td>
                                <td class="table-admin__num">{{ ReportFormatter::count($row['pieces_sold']) }}</td>
                                <td class="table-admin__num">{{ ReportFormatter::count($row['pieces_in_stock']) }}</td>
                                <td class="table-admin__num">{{ ReportFormatter::grams($row['gold_weight_stock']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
@endsection
