{{-- Immutable stone intake history. --}}
@extends('layouts.branch')

@section('title', __('Stone Intakes'))
@section('page-title', __('Stone Intakes'))

@section('content')
<x-page-header :title="__('Stone Intakes')" :subtitle="__('Historical batches. Intakes cannot be edited; a later adjustment will correct mistakes.')">
    <x-slot:actions>
        @can('create', \App\Models\StoneIntake::class)
            <x-btn href="{{ route('branch.stone-intakes.scan') }}">{{ __('New Intake') }}</x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="filter-bar">
    <div>
        <label class="form-label" for="from">{{ __('From') }}</label>
        <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input-field">
    </div>
    <div>
        <label class="form-label" for="to">{{ __('To') }}</label>
        <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input-field">
    </div>
    <div>
        <label class="form-label" for="performed_by">{{ __('Performed by') }}</label>
        <select id="performed_by" name="performed_by" class="input-field">
            <option value="">{{ __('Anyone') }}</option>
            @foreach($users as $staff)
                <option value="{{ $staff->id }}" @selected((string) ($filters['performed_by'] ?? '') === (string) $staff->id)>{{ $staff->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-bar__actions">
        <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
        <a href="{{ route('branch.stone-intakes.index') }}" class="btn btn--secondary">{{ __('Reset') }}</a>
    </div>
</form>

<div class="panel">
    @if($intakes->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No intakes yet')" :message="__('Scan packets on the Stone Intake page to receive stock.')" icon="barcode_scanner" />
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Intake') }}</th>
                        <th class="table-admin__num">{{ __('Types') }}</th>
                        <th class="table-admin__num">{{ __('Qty') }}</th>
                        <th class="table-admin__num">{{ __('Carat') }}</th>
                        <th>{{ __('Performed by') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($intakes as $intake)
                        <tr>
                            <td>
                                <x-table.identity
                                    :href="route('branch.stone-intakes.show', $intake)"
                                    :title="$intake->intake_number"
                                    :meta="setting_date($intake->intake_date)"
                                    icon="package_2"
                                />
                            </td>
                            <td class="table-admin__num">{{ $intake->total_stone_types }}</td>
                            <td class="table-admin__num">{{ $intake->total_quantity }}</td>
                            <td class="table-admin__num">{{ $intake->total_carat }}</td>
                            <td>{{ $intake->creator?->name }} ({{ \App\Support\AttributionFormatter::roleLabel($intake->performed_by_role) }})</td>
                            <td class="table-admin__actions-col">
                                <x-table.actions>
                                    <x-table.action :href="route('branch.stone-intakes.show', $intake)" />
                                </x-table.actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($intakes->total() > 0)
            <div class="panel__footer">
                {{ $intakes->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
