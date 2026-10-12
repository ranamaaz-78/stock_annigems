{{-- Immutable intake detail. --}}
@extends('layouts.branch')

@section('title', $intake->intake_number)
@section('page-title', $intake->intake_number)

@section('content')
<x-page-header :title="$intake->intake_number" subtitle="This intake is a historical record and cannot be edited. Mistakes are corrected later with an adjustment.">
    <x-slot:actions>
        <x-btn href="{{ route('branch.stone-intakes.index') }}" variant="secondary">Back</x-btn>
        <button type="button" class="btn btn--secondary" onclick="window.print()">Print</button>
    </x-slot:actions>
</x-page-header>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4 mb-4">
    <dl class="grid grid-cols-2 md:grid-cols-4 gap-3 text-body-sm">
        <div><dt class="form-label">Date</dt><dd class="font-data-mono">{{ setting_date($intake->intake_date) }}</dd></div>
        <div><dt class="form-label">Stone types</dt><dd class="font-data-mono">{{ $intake->total_stone_types }}</dd></div>
        <div><dt class="form-label">Total quantity</dt><dd class="font-data-mono">{{ $intake->total_quantity }}</dd></div>
        <div><dt class="form-label">Total carat</dt><dd class="font-data-mono">{{ $intake->total_carat }}</dd></div>
        @if((float) $intake->total_weight_grams > 0)
            <div><dt class="form-label">Total grams</dt><dd class="font-data-mono">{{ rtrim(rtrim((string) $intake->total_weight_grams, '0'), '.') }} g</dd></div>
        @endif
    </dl>
    @if($intake->notes)
        <p class="text-body-sm mt-3">{{ $intake->notes }}</p>
    @endif
    <x-attribution :record="$intake" class="mt-4" />
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden">
    <table class="table-admin">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Carat (snapshot)</th>
                <th>Qty</th>
                <th>Scanned</th>
            </tr>
        </thead>
        <tbody>
            @foreach($intake->items as $item)
                <tr>
                    <td class="font-data-mono">
                        @if($item->stone)
                            <a href="{{ route('branch.stones.show', $item->stone) }}" class="form-link">{{ $item->stone->stone_code }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $item->name_snapshot }}</td>
                    <td class="font-data-mono">{{ $item->carat_snapshot }}</td>
                    <td class="font-data-mono">
                        @if($item->weight_grams !== null)
                            {{ rtrim(rtrim((string) $item->weight_grams, '0'), '.') }} g
                        @else
                            {{ $item->quantity }}
                        @endif
                    </td>
                    <td class="font-data-mono">{{ $item->scanned_count }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
