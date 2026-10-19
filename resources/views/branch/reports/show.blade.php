{{--
    One branch report.

    Every report in the system renders through this single page: the shell
    composes the header, tabs, notice, filter bar, meta strip, cards, chart and
    tables from the ReportResult, so a new report needs a service method and a
    catalog entry, not a new view.
--}}
@extends('layouts.branch')

@section('title', $result->title)
@section('page-title', $result->title)

@section('content')
<x-report.shell
    :result="$result"
    :tabs="$tabs"
    :active-tab="$definition['key']"
    :subtitle="$definition['summary']"
    export-route="branch.reports.export"
    :export-query="array_merge(request()->query(), ['report' => $definition['key']])"
    :can-export="$canExport"
    :show-revenue-notice="(bool) $result->meta('money', false)"
>
    <x-slot:filters>
        <x-report.filters
            :available="$definition['filters']"
            :options="$options"
            :filter="$filter"
        />
    </x-slot:filters>

    {{-- Reports that describe present stock ignore the date range; saying so
         prevents someone concluding the branch sold nothing in March. --}}
    @if($result->meta('live_snapshot', false))
        <p class="report-notice">
            <span class="material-symbols-outlined icon icon--sm report-notice__icon" aria-hidden="true">inventory_2</span>
            <span>{{ __('This report describes stock held') }} <strong>{{ __('right now') }}</strong>{{ __('. The date range above does not narrow it.') }}</span>
        </p>
    @elseif($result->meta('ignores_date_range', false))
        <p class="report-notice">
            <span class="material-symbols-outlined icon icon--sm report-notice__icon" aria-hidden="true">schedule</span>
            <span>{{ __('This report covers the whole history rather than the selected date range, because that is the question it answers.') }}</span>
        </p>
    @endif
</x-report.shell>
@endsection
