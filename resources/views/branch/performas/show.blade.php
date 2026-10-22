{{-- Performa detail — outstanding vs settled vs returned, then pay or return. --}}
@extends('layouts.branch')

@section('title', $performa->performa_number)
@section('page-title', __('Performa Detail'))

@section('content')
<x-page-header :title="$performa->performa_number" :subtitle="setting_date($performa->performa_date)">
    <x-slot:actions>
        @if($performa->isOpen())
            @can('update', $performa)
                <x-btn href="{{ route('branch.performas.edit', $performa) }}" variant="secondary">{{ __('Edit') }}</x-btn>
            @endcan
            @can('settle', $performa)
                <x-btn href="{{ route('branch.performas.settle', $performa) }}">{{ __('Settle payment') }}</x-btn>
            @endcan
            @can('returnUnpaid', $performa)
                <x-btn type="button" variant="secondary" data-modal-open="return-modal">{{ __('Return unpaid') }}</x-btn>
            @endcan
        @endif
        <x-btn type="button" variant="secondary" onclick="window.print()">{{ __('Print') }}</x-btn>
        @if(auth()->user()->hasPermission('performas.delete'))
            <x-delete-record type="performas" :record="$performa" text="{{ __('Unpaid goods return to stock and the performa moves to Trash.') }}" />
        @endif
        <x-btn href="{{ route('branch.performas.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel-stack no-print">
    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">{{ $performa->displayCreditorName() }}</h2>
            <x-status-badge :status="$performa->status" />
        </div>
        <div class="panel__body">
            <p class="text-body-sm">
                @if($performa->creditor)
                    <a href="{{ route('branch.creditors.show', $performa->creditor) }}" class="form-link">{{ $performa->creditor->creditor_code }}</a>
                    · {{ $performa->creditor->phone }}
                    @if($performa->creditor->email)
                        · {{ $performa->creditor->email }}
                    @endif
                @endif
            </p>
            @if($performa->notes)
                <p class="text-body-sm">{{ $performa->notes }}</p>
            @endif
        </div>
    </div>

    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">{{ __('Issued goods') }}</h2>
        </div>
        <div class="panel__scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>{{ __('Item') }}</th>
                        <th>{{ __('Code') }}</th>
                        <th>{{ __('Issued') }}</th>
                        <th>{{ __('Settled') }}</th>
                        <th>{{ __('Returned') }}</th>
                        <th>{{ __('Outstanding') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($performa->items as $item)
                        <tr>
                            <td>
                                {{ $item->name_snapshot }}
                                @if($item->purity_snapshot || $item->metal_snapshot)
                                    <span class="text-body-sm">{{ trim(($item->metal_snapshot ?? '').' · '.($item->purity_snapshot ?? ''), ' ·') }}</span>
                                @endif
                            </td>
                            <td class="font-data-mono">{{ $item->item_code_snapshot }}</td>
                            <td class="font-data-mono">{{ $item->isWeighed() ? $item->amountLabel($item->weight_issued) : $item->quantity_issued }}</td>
                            <td class="font-data-mono">{{ $item->isWeighed() ? $item->amountLabel($item->weight_settled) : $item->quantity_settled }}</td>
                            <td class="font-data-mono">{{ $item->isWeighed() ? $item->amountLabel($item->weight_returned) : $item->quantity_returned }}</td>
                            <td class="font-data-mono">{{ $item->amountLabel($item->outstandingAmount()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">{{ __('Settlements') }}</h2>
        </div>
        @if($performa->settlements->isEmpty())
            <div class="panel__body">
                <p class="text-body-sm">{{ __('No payments yet.') }}</p>
            </div>
        @else
            <div class="panel__scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th>{{ __('Invoice') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Method') }}</th>
                            <th>{{ __('Total') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($performa->settlements as $settlement)
                            <tr>
                                <td class="font-data-mono">{{ $settlement->invoice?->invoice_number }}</td>
                                <td>{{ $settlement->settled_at ? setting_date($settlement->settled_at) : '—' }}</td>
                                <td>{{ $settlement->payment_method?->label() }}</td>
                                <td class="font-data-mono">{{ $settlement->invoice ? setting_money($settlement->invoice->grand_total) : '—' }}</td>
                                <td>
                                    @if($settlement->invoice)
                                        <a href="{{ route('branch.invoices.show', $settlement->invoice) }}" class="btn btn--secondary btn--sm">{{ __('View') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<div class="doc-meta">
    <x-attribution :record="$performa" />
</div>

@can('returnUnpaid', $performa)
    @php
        $outstandingReturn = $performa->items->filter(fn ($item) => $item->outstandingQuantity() > 0);
        $returnConfig = [
            'routes' => [
                'return' => route('branch.performas.return', $performa),
                'show' => route('branch.performas.show', $performa),
            ],
        ];
    @endphp
    <x-modal id="return-modal" :title="__('Return unpaid goods')">
        <div id="performa-return-root" data-config="{{ json_encode($returnConfig) }}">
            <p class="text-body-sm">{{ __('Return every unpaid line, or only some pieces / stone qty. Settled lines are not touched.') }}</p>

            @if($outstandingReturn->isEmpty())
                <p class="text-body-sm">{{ __('Nothing unpaid to return.') }}</p>
            @else
                <form id="performa-return-form" class="panel-stack">
                    <div class="panel__scroll">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th>{{ __('Item') }}</th>
                                    <th>{{ __('Outstanding') }}</th>
                                    <th>{{ __('Return') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($outstandingReturn as $item)
                                    <tr data-return-line data-line-id="{{ $item->id }}" data-line-type="{{ $item->line_type->value }}" data-weighed="{{ $item->isWeighed() ? 1 : 0 }}" data-outstanding="{{ $item->outstandingAmount() }}">
                                        <td>
                                            {{ $item->name_snapshot }}
                                            <span class="text-body-sm">{{ $item->item_code_snapshot }}</span>
                                        </td>
                                        <td class="font-data-mono">{{ $item->amountLabel($item->outstandingAmount()) }}</td>
                                        <td>
                                            @if($item->line_type === \App\Enums\LineType::JEWELLERY_ITEM)
                                                <label class="form-check">
                                                    <input type="checkbox" data-return-select>
                                                    {{ __('This piece') }}
                                                </label>
                                            @else
                                                <input
                                                    type="number"
                                                    min="0"
                                                    max="{{ $item->outstandingAmount() }}"
                                                    step="{{ $item->isWeighed() ? '0.001' : '1' }}"
                                                    class="input-field"
                                                    data-return-qty
                                                    placeholder="{{ $item->isWeighed() ? 'grams' : '0' }}"
                                                    aria-label="{{ $item->isWeighed() ? __('Grams to return') : __('Return quantity') }}"
                                                >
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="form-actions">
                        <x-btn type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-btn>
                        <x-btn type="button" variant="secondary" data-return-selected :loading-text="__('Returning…')">{{ __('Return selected') }}</x-btn>
                        <x-btn type="button" variant="danger" data-return-all :loading-text="__('Returning…')">{{ __('Return all unpaid') }}</x-btn>
                    </div>
                </form>
            @endif
        </div>
    </x-modal>
@endcan
{{-- The Print button prints this sheet; the page around it is hidden on paper. --}}
<div class="pd-print-only">
    @include('branch.performas._sheet', ['performa' => $performa, 'a4' => false])
</div>
@endsection
