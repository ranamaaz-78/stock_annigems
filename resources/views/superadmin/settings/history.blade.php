{{--
    SuperAdmin settings change history.

    Compact table filtered by group and date range. Attribution is the
    role snapshot stored on the row, not the actor's current role.
--}}
@extends('layouts.superadmin')

@section('title', __('Settings History'))
@section('page-title', __('Settings History'))

@section('content')
<x-page-header
    :title="__('Settings change history')"
    :subtitle="__('Who changed which key, and which role they held at the time.')"
>
    <x-slot:actions>
        <x-btn href="{{ route('superadmin.settings.index') }}" variant="secondary">
            {{ __('Back to settings') }}
        </x-btn>
    </x-slot:actions>
</x-page-header>

<form method="GET" action="{{ route('superadmin.settings.history') }}" class="filter-bar mb-4">
    <div class="form-grid form-grid--3">
        <div class="form-field">
            <label class="form-label" for="history-group">{{ __('Group') }}</label>
            <select id="history-group" name="group" class="input-field">
                <option value="">{{ __('All groups') }}</option>
                @foreach($groups as $group)
                    <option value="{{ $group->value }}" @selected(($filters['group'] ?? '') === $group->value)>{{ $group->label() }}</option>
                @endforeach
            </select>
        </div>
        <x-form.input name="from" type="date" :label="__('From')" :value="$filters['from'] ?? null" />
        <x-form.input name="to" type="date" :label="__('To')" :value="$filters['to'] ?? null" />
    </div>
    <div class="mt-3">
        <x-btn type="submit" variant="secondary">{{ __('Apply filters') }}</x-btn>
    </div>
</form>

<div class="panel">
    <div class="table-scroll">
        <table class="table-admin table-admin--dense">
            <thead>
                <tr>
                    <th>{{ __('When') }}</th>
                    <th>{{ __('Scope') }}</th>
                    <th>{{ __('Key') }}</th>
                    <th>{{ __('From') }}</th>
                    <th>{{ __('To') }}</th>
                    <th>{{ __('Changed by') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td class="text-data-mono">{{ setting_datetime($row->created_at) }}</td>
                        <td>{{ $row->scope->label() }}@if($row->branch) · {{ $row->branch->name }}@endif</td>
                        <td class="table-admin__label">{{ $row->key }}</td>
                        <td class="text-data-mono">{{ $row->old_value ?? '—' }}</td>
                        <td class="text-data-mono">{{ $row->new_value ?? '—' }}</td>
                        <td>
                            <x-audit-actor
                                :user="$row->changer"
                                :role="$row->changed_by_role"
                                :at="$row->created_at"
                            />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty-state
                                :title="__('No changes yet')"
                                :message="__('Saving a settings group writes a row here.')"
                                icon="history"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($rows->hasPages())
        <div class="panel__footer">{{ $rows->links() }}</div>
    @endif
</div>
@endsection
