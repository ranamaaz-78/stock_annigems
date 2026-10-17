{{-- Stone detail: lot fields, cost calculation, barcode, movement ledger, attribution. --}}
@extends('layouts.branch')

@section('title', $stone->stone_code)
@section('page-title', $stone->stone_code)

@section('content')
<x-page-header :title="$stone->name" :subtitle="$stone->stone_code">
    <x-slot:actions>
        <x-btn href="{{ route('branch.stones.index') }}" variant="secondary">Back</x-btn>
        @can('update', $stone)
            <x-btn href="{{ route('branch.stones.edit', $stone) }}">Edit</x-btn>
        @endcan
        @can('printLabel', $stone)
            <x-btn href="{{ route('branch.stones.label', $stone) }}" variant="secondary" data-label-open="{{ $stone->uuid }}">Print Label</x-btn>
        @endcan
        @can('delete', $stone)
            <form method="POST" action="{{ route('branch.stones.destroy', $stone) }}" data-loading-form data-confirm-delete-stone
                  data-remaining="{{ $stone->isWeighed() ? rtrim(rtrim(number_format((float) $stone->weight_grams, 3, '.', ''), '0'), '.').' g' : ((int) $stone->quantity).' pcs' }}" class="inline">
                @csrf
                @method('DELETE')
                <x-btn type="submit" variant="danger" loading-text="Deleting...">Delete</x-btn>
            </form>
        @endcan
        @can('deactivate', $stone)
            @if($stone->is_active)
                <form method="POST" action="{{ route('branch.stones.deactivate', $stone) }}" data-loading-form data-confirm-deactivate class="inline">
                    @csrf
                    <x-btn type="submit" variant="danger" loading-text="Deactivating...">Deactivate</x-btn>
                </form>
            @else
                <form method="POST" action="{{ route('branch.stones.activate', $stone) }}" data-loading-form class="inline">
                    @csrf
                    <x-btn type="submit" loading-text="Activating...">Activate</x-btn>
                </form>
            @endif
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="panel-stack">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter items-start">
        <div class="panel lg:col-span-2">
            <div class="panel__header">
                <h2 class="panel__title">Lot</h2>
                <x-status-badge :status="$stone->is_active ? 'active' : 'inactive'" />
            </div>
            <div class="panel__body">
                {{-- Only the fields the supplier sheet actually carries. --}}
                <dl class="detail-list">
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Shape / cut</dt>
                        <dd class="detail-list__value">{{ $stone->shape ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Dimension</dt>
                        <dd class="detail-list__value font-data-mono">{{ $stone->dimension ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Carat</dt>
                        <dd class="detail-list__value font-data-mono">{{ $stone->caratLabel() }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Weight (g)</dt>
                        <dd class="detail-list__value font-data-mono">{{ $stone->gramsLabel() }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Pairs</dt>
                        <dd class="detail-list__value font-data-mono">{{ $stone->pairs }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Tray no.</dt>
                        <dd class="detail-list__value">{{ $stone->tray_no ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Image code</dt>
                        <dd class="detail-list__value font-data-mono">{{ $stone->image_code ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">On hand</dt>
                        <dd class="detail-list__value font-data-mono {{ $stone->isOutOfStock() ? 'stone-detail__value--empty' : '' }}">
                            {{ $stone->onHandLabel() }}
                        </dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Lot status</dt>
                        <dd class="detail-list__value">{{ $stone->status?->label() ?? '—' }}</dd>
                    </div>
                    @if($stone->remarks)
                        <div class="detail-list__item detail-list__item--full">
                            <dt class="detail-list__term">Remarks</dt>
                            <dd class="detail-list__value">{{ $stone->remarks }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>

        <div class="panel">
            <div class="panel__header">
                <h2 class="panel__title">Image</h2>
            </div>
            <div class="panel__body">
                @if($stone->image && auth()->user()->can('view', $stone->image))
                    <img src="{{ $stone->image->url() }}" alt="{{ $stone->image->name }}" class="stone-detail__image">
                    <p class="detail-list__term">{{ $stone->image->name }}</p>
                @elseif($stone->image_code)
                    <p class="detail-list__value">No picture named <strong>{{ $stone->image_code }}</strong> in Stones → Images yet. Upload one with that name and it attaches automatically.</p>
                @else
                    <p class="detail-list__value">No image code set. Edit this stone and enter a picture name.</p>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel__header">
                <h2 class="panel__title">Packet</h2>
            </div>
            <div class="panel__body stone-packet">
                <p class="detail-list__term">Packet code</p>
                <p class="stone-packet__code">{{ $stone->stone_code }}</p>
                @if($stone->hasPacketBarcode() && $barcodeUri)
                    <p class="detail-list__term">Packet barcode</p>
                    <img src="{{ $barcodeUri }}" alt="Barcode {{ $stone->barcode }}" class="stone-packet__barcode">
                    <p class="stone-packet__number">{{ $stone->barcode }}</p>
                @else
                    <p class="detail-list__value">No packet barcode was issued for this stone.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">Cost</h2>
        </div>
        <div class="panel__body">
            <dl class="detail-list">
                <div class="detail-list__item">
                    <dt class="detail-list__term">Priced</dt>
                    <dd class="detail-list__value">{{ $stone->pricing_mode?->label() ?? '—' }}</dd>
                </div>
                <div class="detail-list__item">
                    <dt class="detail-list__term">Rate paid</dt>
                    <dd class="detail-list__value font-data-mono">{{ $stone->rateLabel() }}</dd>
                </div>
                @if($stone->last_rate !== null)
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Last rate</dt>
                        <dd class="detail-list__value font-data-mono">
                            {{ $stone->lastRateLabel() }}
                            @if($stone->rate_changed_at)
                                <span class="table-meta">replaced {{ setting_datetime($stone->rate_changed_at) }}</span>
                            @endif
                        </dd>
                    </div>
                @endif
                @if($stone->piece_price !== null)
                    <div class="detail-list__item">
                        <dt class="detail-list__term">Cost per piece</dt>
                        <dd class="detail-list__value font-data-mono">{{ setting_money($stone->piece_price) }}</dd>
                    </div>
                @endif
                <div class="detail-list__item">
                    <dt class="detail-list__term">Total cost</dt>
                    <dd class="detail-list__value font-data-mono">{{ setting_money($stone->total_cost) }}</dd>
                </div>
            </dl>

            {{-- The stored calculation, so anyone can audit how the cost was reached. --}}
            <div class="stone-calc mt-4">
                <span class="stone-calc__label">{{ $stone->pricing_mode?->label() ?? 'Not priced' }}</span>
                <span class="stone-calc__formula">{{ $stone->cost_formula ?? 'Not calculated.' }}</span>
                <span class="stone-calc__total">{{ setting_money($stone->total_cost) }}</span>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">Record</h2>
        </div>
        <div class="panel__body">
            <x-attribution :record="$stone" />
        </div>
    </div>

    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">Stock movements</h2>
        </div>
    @if($stone->stockMovements->isEmpty())
        <div class="panel__body">
            <p class="detail-list__value">No movements yet. Stock changes through Stone Intake and edits.</p>
        </div>
    @else
        <div class="panel__scroll">
        <table class="table-admin">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Type</th>
                    <th>Change</th>
                    <th>Before</th>
                    <th>After</th>
                    <th>Reference</th>
                    <th>Performed by</th>
                </tr>
            </thead>
            <tbody>
                @foreach($stone->stockMovements as $movement)
                    <tr>
                        <td class="font-data-mono">{{ setting_datetime($movement->created_at) }}</td>
                        <td>{{ $movement->type->label() }}</td>
                        @if($movement->isWeightMovement())
                            {{-- A gram lot moves in grams; the piece columns did not change. --}}
                            @php($grams = fn ($value) => rtrim(rtrim((string) $value, '0'), '.').' g')
                            <td class="font-data-mono">{{ $movement->weight_change > 0 ? '+' : '' }}{{ $grams($movement->weight_change) }}</td>
                            <td class="font-data-mono">{{ $grams($movement->weight_before) }}</td>
                            <td class="font-data-mono">{{ $grams($movement->weight_after) }}</td>
                        @else
                            <td class="font-data-mono">{{ $movement->quantity_change > 0 ? '+' : '' }}{{ $movement->quantity_change }}</td>
                            <td class="font-data-mono">{{ $movement->quantity_before }}</td>
                            <td class="font-data-mono">{{ $movement->quantity_after }}</td>
                        @endif
                        <td>
                            @if($movement->reference instanceof \App\Models\StoneIntake)
                                <a href="{{ route('branch.stone-intakes.show', $movement->reference) }}" class="form-link">{{ $movement->reference->intake_number }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $movement->creator?->name }} ({{ \App\Support\AttributionFormatter::roleLabel($movement->performed_by_role) }})</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
    </div>
</div>

@include('branch.stones._label-modal')
@endsection
