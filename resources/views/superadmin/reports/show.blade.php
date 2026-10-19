{{--
    One SuperAdmin aggregate report.

    Identical shell to the branch report page, with one extra control: the
    All Branches / Single Branch selector. Choosing a single branch narrows the
    figures and unlocks nothing else — there are no row links anywhere on this
    page, because there is nothing for them to point at.
--}}
@extends('layouts.superadmin')

@section('title', $result->title)
@section('page-title', $result->title)

@section('content')
<x-report.shell
    :result="$result"
    :tabs="$tabs"
    :active-tab="$definition['key']"
    :subtitle="$definition['summary']"
    export-route="superadmin.reports.export"
    :export-query="array_merge(request()->query(), ['report' => $definition['key']])"
    :can-export="$canExport"
    :show-revenue-notice="(bool) $result->meta('money', false)"
>
    <x-slot:filters>
        {{-- Branch scope is the SuperAdmin-only filter. It is a branch uuid and
             nothing else: no route on this surface accepts a record uuid. --}}
        <div>
            <label for="branch" class="form-label">{{ __('Branch scope') }}</label>
            <select id="branch" name="branch" class="input-field">
                <option value="">{{ __('All branches (consolidated)') }}</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->uuid }}" @selected($selectedBranch === $branch->uuid)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>

        <x-report.filters
            :available="$definition['filters']"
            :options="[]"
            :filter="$filter"
        />
    </x-slot:filters>

    @if($result->meta('live_snapshot', false))
        <p class="report-notice">
            <span class="material-symbols-outlined icon icon--sm report-notice__icon" aria-hidden="true">inventory_2</span>
            <span>{{ __('This report describes stock held') }} <strong>{{ __('right now') }}</strong>{{ __('. The date range above does not narrow it.') }}</span>
        </p>
    @endif
</x-report.shell>
@endsection
