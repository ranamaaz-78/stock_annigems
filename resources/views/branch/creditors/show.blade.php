{{-- Creditor detail: profile plus performas issued against them. --}}
@extends('layouts.branch')

@section('title', $creditor->name)
@section('page-title', __('Creditor Detail'))

@section('content')
<x-page-header :title="$creditor->name" :subtitle="$creditor->creditor_code">
    <x-slot:actions>
        @can('update', $creditor)
            <x-btn href="{{ route('branch.creditors.edit', $creditor) }}" variant="secondary">{{ __('Edit') }}</x-btn>
        @endcan
        @can('create', \App\Models\Performa::class)
            @if($creditor->status)
                <x-btn href="{{ route('branch.performas.create', ['creditor' => $creditor->uuid]) }}">{{ __('New Performa') }}</x-btn>
            @endif
        @endcan
        <x-btn href="{{ route('branch.creditors.index') }}" variant="secondary">{{ __('Back') }}</x-btn>
    </x-slot:actions>
</x-page-header>

<div class="panel-stack">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter items-start">
        <div class="panel lg:col-span-2">
            <div class="panel__header">
                <h2 class="panel__title">{{ __('Profile') }}</h2>
                <x-status-badge :status="$creditor->status" />
            </div>
            <div class="panel__body">
                <dl class="detail-list">
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Phone') }}</dt>
                        <dd class="detail-list__value font-data-mono">{{ $creditor->phone }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Alternate phone') }}</dt>
                        <dd class="detail-list__value font-data-mono">{{ $creditor->alternate_phone ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Email') }}</dt>
                        <dd class="detail-list__value">{{ $creditor->email ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('City') }}</dt>
                        <dd class="detail-list__value">{{ $creditor->city ?? '—' }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('Registered') }}</dt>
                        <dd class="detail-list__value">{{ setting_date($creditor->created_at) }}</dd>
                    </div>
                    <div class="detail-list__item">
                        <dt class="detail-list__term">{{ __('NIE / NIF') }}</dt>
                        <dd class="detail-list__value">{{ $creditor->nie ?: '—' }}</dd>
                    </div>
                    <div class="detail-list__item detail-list__item--full">
                        <dt class="detail-list__term">{{ __('Address') }}</dt>
                        <dd class="detail-list__value">{{ $creditor->address ?? '—' }}</dd>
                    </div>
                    @if($creditor->notes)
                        <div class="detail-list__item detail-list__item--full">
                            <dt class="detail-list__term">{{ __('Internal notes') }}</dt>
                            <dd class="detail-list__value">{{ $creditor->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>

        <div class="panel">
            <div class="panel__header">
                <h2 class="panel__title">{{ __('Record') }}</h2>
            </div>
            <div class="panel__body">
                <x-attribution :record="$creditor" />

                @can('deactivate', $creditor)
                    @if($creditor->status)
                        <form
                            method="POST"
                            action="{{ route('branch.creditors.deactivate', $creditor) }}"
                            id="deactivate-creditor-form"
                            class="form-actions"
                        >
                            @csrf
                            <input type="hidden" name="confirm" value="1">
                            <x-btn type="button" variant="danger" id="deactivate-creditor-btn">{{ __('Deactivate') }}</x-btn>
                        </form>
                    @else
                        <form
                            method="POST"
                            action="{{ route('branch.creditors.activate', $creditor) }}"
                            data-loading-form
                            class="form-actions"
                        >
                            @csrf
                            <x-btn type="submit" :loading-text="__('Activating...')">{{ __('Activate') }}</x-btn>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">{{ __('Performas') }}</h2>
            <p class="panel__subtitle">{{ __('Issued against this creditor') }}</p>
        </div>
        @if($performas->isEmpty())
            <div class="panel__body">
                <x-empty-state :title="__('No performas yet')" :message="__('Issue a performa against this creditor when they take goods on credit.')" icon="assignment" />
            </div>
        @else
            <div class="panel__scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th>{{ __('Number') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Outstanding') }}</th>
                            <th>{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($performas as $performa)
                            @php $outstanding = $performa->outstandingSummary(); @endphp
                            <tr>
                                <td class="font-data-mono">
                                    <a href="{{ route('branch.performas.show', $performa) }}" class="form-link">{{ $performa->performa_number }}</a>
                                </td>
                                <td>{{ setting_date($performa->performa_date) }}</td>
                                <td class="font-data-mono">
                                    {{ $outstanding['jewellery'] }} {{ __('pc') }}
                                    · {{ $outstanding['stones'] }} {{ __('st') }}
                                </td>
                                <td><x-status-badge :status="$performa->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
    /** Confirm before deactivating — the creditor disappears from the performa picker. */
    document.getElementById('deactivate-creditor-btn')?.addEventListener('click', async () => {
        const ok = await window.AnnigemsAlert.confirmAction({
            title: @js(__('Deactivate creditor?')),
            text: @js(__('They will no longer appear when issuing a performa. Existing performas are kept.')),
            confirmButtonText: 'Deactivate',
        });
        if (ok) {
            document.getElementById('deactivate-creditor-form').submit();
        }
    });
</script>
@endpush
@endsection
