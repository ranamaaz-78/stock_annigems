{{--
    Reports landing page (Admin only).

    Lists every report family and what each report answers, so an owner can
    find the right one without opening five. The list is generated from
    ReportCatalog, which is the same source the routes and the export endpoint
    validate against.
--}}
@extends('layouts.branch')

@section('title', 'Reports')
@section('page-title', 'Reports')

@section('content')
<x-page-header
    title="Reports"
    subtitle="Revenue, stock, customer and audit reporting for this branch."
/>

{{-- The disclaimer belongs here as much as on a report: this is where someone
     decides which figure to go and look at. --}}
<p class="report-notice">
    <span class="material-symbols-outlined icon icon--sm report-notice__icon" aria-hidden="true">info</span>
    <span>
        Money figures throughout these reports are <strong>revenue</strong>, not profit. Purchase cost is not
        recorded by the system, so margin cannot be calculated. Voided invoices are excluded from every total.
    </span>
</p>

@foreach($families as $familyKey => $family)
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
                                <a href="{{ route('branch.reports.show', $key) }}" class="form-link">{{ $report['label'] }}</a>
                            </td>
                            <td>{{ $report['summary'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach

{{-- Queued exports land here rather than in the browser, so the user has
     somewhere to come back to once a large file has finished generating. --}}
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
                                    <a href="{{ route('branch.reports.download', $export) }}" class="form-link">Download</a>
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
