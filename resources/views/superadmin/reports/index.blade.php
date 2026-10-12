{{--
    SuperAdmin reports landing page.

    A shorter list than the branch one by design: every entry here is an
    aggregate. Customer purchase history, pending quotation lists, per-invoice
    discounts and the movement ledgers are absent because they are record
    listings, which spec 7.4 keeps out of SuperAdmin's reach entirely.
--}}
@extends('layouts.superadmin')

@section('title', 'Reports')
@section('page-title', 'Reports')

@section('content')
<x-page-header
    title="Reports"
    subtitle="Consolidated and per-branch figures across the whole business."
/>

<p class="report-notice">
    <span class="material-symbols-outlined icon icon--sm report-notice__icon" aria-hidden="true">info</span>
    <span>
        Money figures are <strong>revenue</strong>, not profit — purchase cost is not recorded, so margin cannot be
        calculated. These reports show <strong>aggregated figures only</strong>: individual invoices, quotations,
        items, stones and customer purchases are not reachable from here.
    </span>
</p>

@foreach($families as $family)
    <div class="panel report-section">
        <div class="panel-header">
            <h2 class="panel-header__title text-headline-sm">
                <span class="material-symbols-outlined icon icon--sm icon--primary" aria-hidden="true">{{ $family['icon'] }}</span>
                {{ $family['label'] }}
            </h2>
        </div>
        <div class="table-scroll">
            <table class="table-admin table-admin--dense">
                <tbody>
                    @foreach($family['reports'] as $key => $report)
                        <tr>
                            <td class="table-admin__label">
                                <a href="{{ route('superadmin.reports.show', $key) }}" class="form-link">{{ $report['label'] }}</a>
                            </td>
                            <td>{{ $report['summary'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach

@if($recentExports->isNotEmpty())
    <div class="panel">
        <div class="panel-header">
            <h2 class="panel-header__title text-headline-sm">Recent Exports</h2>
        </div>
        <div class="table-scroll">
            <table class="table-admin table-admin--dense">
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Requested</th>
                        <th>Format</th>
                        <th class="table-admin__num">Rows</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentExports as $export)
                        <tr>
                            <td class="table-admin__label">{{ $export->report_title }}</td>
                            <td class="text-data-mono">{{ setting_datetime($export->created_at) }}</td>
                            <td>{{ strtoupper($export->format) }}</td>
                            <td class="table-admin__num">{{ number_format($export->row_count) }}</td>
                            <td><x-status-badge :status="$export->status" /></td>
                            <td>
                                @if($export->isDownloadable())
                                    <a href="{{ route('superadmin.reports.download', $export) }}" class="form-link">Download</a>
                                @elseif($export->failure_reason)
                                    <span class="text-body-sm">{{ $export->failure_reason }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
