{{-- History of stone Excel imports and the rows each one set aside. --}}
@extends('layouts.branch')

@section('title', __('Import History'))
@section('page-title', __('Import History'))

@section('content')
<x-page-header :title="__('Import History')" :subtitle="__('Every Excel import: how many rows went in and how many need a fix.')">
    <x-slot:actions>
        <x-btn href="{{ route('branch.stones.index') }}" variant="secondary">{{ __('Back to Stones') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel">
    @if($imports->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No imports yet')" :message="__('Use Upload Excel on the Stones page to import a supplier sheet.')" icon="upload_file" />
        </div>
    @else
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('File') }}</th>
                        <th>{{ __('Date & time') }}</th>
                        <th>{{ __('Imported by') }}</th>
                        <th class="table-admin__num">{{ __('Total rows') }}</th>
                        <th class="table-admin__num">{{ __('Inserted') }}</th>
                        <th class="table-admin__num">{{ __('Not inserted') }}</th>
                        <th class="table-admin__actions-col">{{ __('Bad rows') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($imports as $import)
                        <tr>
                            <td>
                                <x-table.identity
                                    :title="$import->filename"
                                    icon="upload_file"
                                />
                                @unless($import->isFinished())
                                    <span class="stone-import__chip stone-import__chip--error" title="{{ __('The import stopped before it finished. The rows shown as inserted are in your stock.') }}">{{ __('Stopped early') }}</span>
                                @endunless
                            </td>
                            <td class="font-data-mono">{{ setting_datetime($import->created_at) }}</td>
                            <td>{{ $import->creator?->name }} ({{ \App\Support\AttributionFormatter::roleLabel($import->created_by_role) }})</td>
                            <td class="table-admin__num"><span class="badge badge--inactive">{{ number_format($import->total_rows) }}</span></td>
                            <td class="table-admin__num"><span class="badge badge--completed">{{ number_format($import->inserted_rows) }}</span></td>
                            <td class="table-admin__num">
                                @if($import->rejected_rows > 0)
                                    <span class="badge badge--failed">{{ number_format($import->rejected_rows) }}</span>
                                @else
                                    <span class="badge badge--completed">0</span>
                                @endif
                            </td>
                            <td class="table-admin__actions-col">
                                @if($import->hasRejects())
                                    <a href="{{ route('branch.stone-imports.rejects', $import) }}" class="btn btn--danger">
                                        <span class="material-symbols-outlined icon icon--sm">download</span>
                                        {{ __('Download') }}
                                    </a>
                                @else
                                    <span class="table-meta">{{ __('None') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($imports->total() > 0)
            <div class="panel__footer">
                {{ $imports->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
