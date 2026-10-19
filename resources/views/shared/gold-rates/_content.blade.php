{{--
    Shared gold rates content for SuperAdmin (manage) and Branch (read-only).

    Expects: $currentRates, $history, $purities, $filters, $readonly and — when
    $readonly is false — $deviationThreshold.
--}}
@php
    $readonly = $readonly ?? false;

    // purity_id => current rate, handed to the deviation-warning script below.
    $currentRateMap = $currentRates
        ->mapWithKeys(fn ($row) => [$row['purity']->id => $row['rate']?->rate_per_gram])
        ->all();
@endphp

<div class="panel-stack">
    @if($readonly)
        <x-alert type="info">
            {{ __('Gold rates are set by the SuperAdmin. Contact your system administrator to request a rate change.') }}
        </x-alert>
    @endif

    {{-- Current rates + (SuperAdmin only) the update form, side by side. --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-gutter items-start">
        <div class="panel">
            <div class="panel__header">
                <h2 class="panel__title">{{ __('Current rates') }}</h2>
                <p class="panel__subtitle">{{ __('Per gram') }}</p>
            </div>
            @if($currentRates->isEmpty())
                <div class="panel__body">
                    <x-empty-state
                        :title="__('No active purities')"
                        :message="__('Add a metal purity before setting gold rates.')"
                        icon="brightness_high"
                    />
                </div>
            @else
            <div class="panel__scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th>{{ __('Purity') }}</th>
                            <th>{{ __('Rate') }}</th>
                            <th>{{ __('Effective') }}</th>
                            <th>{{ __('Set by') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($currentRates as $row)
                            <tr>
                                <td>{{ $row['purity']->metalType?->name }} — {{ $row['purity']->name }}</td>
                                <td class="font-data-mono">
                                    @if($row['rate'])
                                        {{ setting_money($row['rate']->rate_per_gram) }}
                                    @else
                                        {{ __('Not set') }}
                                    @endif
                                </td>
                                <td>{{ $row['rate']?->effective_date ? setting_date($row['rate']->effective_date) : '—' }}</td>
                                <td>
                                    @if($row['rate'])
                                        <x-audit-actor
                                            :user="$row['rate']->creator"
                                            :role="$row['rate']->created_by_role"
                                            :at="$row['rate']->created_at"
                                        />
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

        @unless($readonly)
            <div class="panel">
                <div class="panel__header">
                    <h2 class="panel__title">{{ __('Update rates') }}</h2>
                </div>
                <div class="panel__body">
                    <form
                        method="POST"
                        action="{{ route('superadmin.gold-rates.store') }}"
                        id="gold-rates-form"
                        data-validate-form
                        data-loading-form
                    >
                        @csrf
                        {{-- Set to 1 by the deviation confirmation dialog before re-submitting. --}}
                        <input type="hidden" name="confirm_deviation" id="confirm_deviation" value="0">

                        <x-form.input
                            name="effective_date"
                            :label="__('Effective date')"
                            type="date"
                            :max="now()->toDateString()"
                            :value="old('effective_date', now()->toDateString())"
                            required
                        />

                        @error('rates')
                            <x-alert type="error" class="mt-3">{{ $message }}</x-alert>
                        @enderror

                        <div class="rate-grid mt-4">
                            @foreach($currentRates as $row)
                                <div class="rate-grid__row">
                                    <div class="rate-grid__meta">
                                        <label class="rate-grid__purity" for="field-rates-{{ $row['purity']->id }}">
                                            {{ $row['purity']->metalType?->name }} {{ $row['purity']->name }}
                                        </label>
                                        <span class="rate-grid__current">
                                            @if($row['rate'])
                                                {{ __('Now :rate', ['rate' => setting_money($row['rate']->rate_per_gram)]) }}
                                            @else
                                                {{ __('Not set yet') }}
                                            @endif
                                        </span>
                                    </div>
                                    <x-form.input
                                        name="rates[{{ $row['purity']->id }}]"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        :value="old('rates.'.$row['purity']->id)"
                                        placeholder="Rate / g"
                                    />
                                </div>
                            @endforeach
                        </div>

                        <p class="form-help mt-3">
                            {{ __('Leave a purity blank to keep its current rate. Each save inserts new history rows — previous rates are never overwritten.') }}
                        </p>

                        <div class="form-actions">
                            <x-btn type="submit" :loading-text="__('Saving rates...')">{{ __('Save rates') }}</x-btn>
                        </div>
                    </form>
                </div>
            </div>
        @endunless
    </div>

    {{-- Full insert-only history with date / purity filters. --}}
    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title">{{ __('Rate history') }}</h2>
        </div>

        <div class="panel__body">
            <form method="GET" class="filter-bar filter-bar--flush">
                <x-form.input name="from" :label="__('From')" type="date" :value="$filters['from'] ?? ''" />
                <x-form.input name="to" :label="__('To')" type="date" :value="$filters['to'] ?? ''" />
                <x-form.select
                    name="purity_id"
                    :label="__('Purity')"
                    :placeholder="__('All purities')"
                    :options="$purities->mapWithKeys(fn ($p) => [$p->id => trim(($p->metalType?->name ?? '').' '.$p->name)])->all()"
                    :value="$filters['purity_id'] ?? ''"
                />
                <div class="filter-bar__actions">
                    <x-btn type="submit" variant="secondary">{{ __('Filter') }}</x-btn>
                    <a href="{{ url()->current() }}" class="btn btn--secondary">{{ __('Reset') }}</a>
                </div>
            </form>
        </div>

        @if($history->isEmpty())
            <div class="panel__footer">
                <x-empty-state
                    :title="__('No rate history yet')"
                    :message="__('Rates you save will be listed here with who changed them and when.')"
                    icon="history"
                />
            </div>
        @else
            <div class="panel__scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th>{{ __('Purity') }}</th>
                            <th>{{ __('Rate / g') }}</th>
                            <th>{{ __('Effective date') }}</th>
                            <th>{{ __('Set by') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($history as $rate)
                            <tr>
                                <td>{{ $rate->purity?->metalType?->name }} {{ $rate->purity?->name }}</td>
                                <td class="font-data-mono">{{ setting_money($rate->rate_per_gram) }}</td>
                                <td>{{ setting_date($rate->effective_date) }}</td>
                                <td>
                                    <x-audit-actor
                                        :user="$rate->creator"
                                        :role="$rate->created_by_role"
                                        :at="$rate->created_at"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($history->hasPages())
                <div class="panel__footer">
                    {{ $history->withQueryString()->links() }}
                </div>
            @endif
        @endif
    </div>
</div>

@unless($readonly)
    @push('scripts')
    <script>
        /**
         * Warn before saving a rate that deviates sharply from the current one.
         * Mirrors GoldRateService::assertDeviationAccepted so the operator sees
         * the warning before the round trip; the backend still enforces it.
         */
        (function () {
            const form = document.getElementById('gold-rates-form');
            if (!form) return;

            const threshold = {{ (int) ($deviationThreshold ?? 20) }};
            const currentRates = @json($currentRateMap);

            form.addEventListener('submit', function (event) {
                const confirmField = form.querySelector('#confirm_deviation');
                if (confirmField.value === '1') return;

                const offenders = [];

                form.querySelectorAll('[name^="rates["]').forEach((input) => {
                    const match = input.name.match(/rates\[(\d+)\]/);
                    if (!match) return;

                    const current = parseFloat(currentRates[match[1]]);
                    const next = parseFloat(input.value);
                    if (!current || !next || current <= 0) return;

                    if (Math.abs((next - current) / current) * 100 > threshold) {
                        offenders.push(input.name);
                    }
                });

                if (offenders.length === 0) return;

                event.preventDefault();
                // The loading helper already disabled Save; restore so they can retry or confirm.
                form._loadingController?.restore();

                window.AnnigemsAlert.confirmAction({
                    title: @js(__('Large rate change')),
                    text: {{ \Illuminate\Support\Js::from(__('One or more rates differ by more than :threshold% from the current rate. Save anyway?')) }}.replace(':threshold', threshold),
                    confirmButtonText: @js(__('Yes, save rates')),
                }).then((ok) => {
                    if (!ok) return;
                    confirmField.value = '1';
                    form.requestSubmit();
                });
            });
        })();
    </script>
    @endpush
@endunless
