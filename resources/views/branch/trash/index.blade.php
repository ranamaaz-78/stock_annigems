{{-- Trash: soft-deleted records, one tab per type. Restore only; nothing is ever purged. --}}
@extends('layouts.branch')

@section('title', __('Trash'))
@section('page-title', __('Trash'))

@section('content')
<x-page-header :title="__('Trash')" :subtitle="__('Deleted records stay here. You can restore them, but they can never be deleted permanently.')" />

@php
    $tabs = collect($types)->map(fn (string $type): array => [
        'key' => $type,
        'label' => __($labels[$type]).' ('.$counts[$type].')',
        'url' => route('branch.trash.index', ['tab' => $type]),
    ])->all();
@endphp

<x-report.tabs :tabs="$tabs" :active="$tab" />

<div class="panel">
    @if($records->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('Trash is empty')" :message="__('Nothing has been deleted here.')" icon="delete" />
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Number / code') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Deleted at') }}</th>
                        <th class="table-admin__actions-col">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($records as $record)
                        @php($row = $trash->describe($record))
                        <tr>
                            <td class="font-data-mono">{{ $row['code'] }}</td>
                            <td>{{ $row['name'] ?: '—' }}</td>
                            <td>{{ $row['status'] ?? '—' }}</td>
                            <td>{{ setting_date($record->deleted_at) }}</td>
                            <td class="table-admin__actions-col">
                                <form method="POST" action="{{ route('branch.trash.restore', ['type' => $tab, 'uuid' => $record->uuid]) }}">
                                    @csrf
                                    <x-btn type="submit" variant="secondary">{{ __('Restore') }}</x-btn>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="panel__footer">
            {{ $records->links() }}
        </div>
    @endif
</div>
@endsection
