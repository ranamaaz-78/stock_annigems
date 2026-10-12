{{-- Stone intake scan workspace. Scan input sits OUTSIDE the save form so Enter never submits. --}}
@extends('layouts.branch')

@section('title', 'Stone Intake')
@section('page-title', 'Stone Intake')

@section('content')
<x-page-header title="Stone Intake" subtitle="Receive stock into the branch: scan or search a stone, set the quantity, then save the batch.">
    <x-slot:actions>
        @can('viewAny', \App\Models\StoneIntake::class)
            <x-btn href="{{ route('branch.stone-intakes.index') }}" variant="secondary">
                <span class="material-symbols-outlined icon icon--sm">history</span>
                Intake history
            </x-btn>
        @endcan
    </x-slot:actions>
</x-page-header>

<div id="stone-intake"
     data-lookup-url="{{ route('branch.stone-intakes.lookup') }}"
     data-draft-url="{{ route('branch.stone-intakes.draft.save') }}"
     data-discard-url="{{ route('branch.stone-intakes.draft.discard') }}"
     data-save-url="{{ route('branch.stone-intakes.store') }}"
     data-search-url="{{ route('branch.stones.search') }}"
     data-store-stone-url="{{ route('branch.stones.store') }}"
     data-csrf="{{ csrf_token() }}"
     data-debounce="{{ $draftDebounceMs }}"
     data-qty-max="{{ config('annigems.stones.intake_quantity_max', 10000) }}"
     data-can-register="{{ $canRegister ? '1' : '0' }}"
     data-scanner='@json($scanner)'
     data-draft='@json($draft)'>

    @if($draft)
        <div class="alert alert--info mb-4" data-draft-banner>
            An unsaved batch was recovered from the server.
            <button type="button" class="form-link" data-discard-draft>Discard it</button>
        </div>
    @endif

    <div class="intake">
        <div class="intake__main">

            {{-- Scan station. The field is NOT inside a form: Enter never saves the intake. --}}
            <section class="panel intake-scan">
                <div class="panel__header">
                    <div>
                        <h2 class="panel__title">Scan station</h2>
                        <p class="panel__subtitle">Scan a packet, or type a barcode, STN code or stone name.</p>
                    </div>
                    <div class="intake-scan__tools">
                        <x-btn type="button" variant="secondary" class="btn--sm is-active" data-scan-toggle aria-pressed="true">Scanning on</x-btn>
                        <label class="form-check">
                            <input type="checkbox" data-mute-beep>
                            Mute beep
                        </label>
                    </div>
                </div>

                <div class="panel__body">
                    <div class="intake-scan__row">
                        <div class="pos-search pos-search--scan scan-input-wrap intake-find is-ready" data-scan-wrap>
                            <span class="pos-search__icon pos-search__icon--scan" aria-hidden="true">
                                <span class="material-symbols-outlined icon icon--md">barcode_scanner</span>
                            </span>
                            <input
                                id="scan-input"
                                type="text"
                                class="input-field pos-search__input pos-search__input--scan"
                                autocomplete="off"
                                role="combobox"
                                aria-label="Barcode, code or stone name"
                                aria-expanded="false"
                                aria-controls="intake-suggest"
                                placeholder="Scan, or type a barcode / code / name"
                                data-scan-input
                            >
                            <span class="pos-scan__ready">
                                <span class="material-symbols-outlined icon icon--xs">sensors</span>
                                <span data-scan-state>Ready</span>
                            </span>
                            <ul id="intake-suggest" class="pos-suggest__list is-hidden" role="listbox" hidden data-suggest></ul>
                        </div>
                        <x-btn type="button" data-lookup-button>
                            <span class="material-symbols-outlined icon icon--sm">add</span>
                            Add
                        </x-btn>
                        @if($canRegister)
                            <x-btn type="button" variant="secondary" data-open-register>
                                <span class="material-symbols-outlined icon icon--sm">diamond</span>
                                New lot
                            </x-btn>
                        @endif
                    </div>

                    <p data-scan-message class="form-error is-hidden mt-2"></p>
                    @if($canRegister)
                        <p class="form-help mt-1 hidden" data-register-shortcut>
                            Not found.
                            <button type="button" class="form-link" data-open-register>Register this stone</button>
                        </p>
                    @endif

                    <ul class="intake-tips">
                        <li class="intake-tip">
                            <span class="material-symbols-outlined icon icon--xs">barcode_scanner</span>
                            Each scan adds +1
                        </li>
                        <li class="intake-tip">
                            <span class="material-symbols-outlined icon icon--xs">edit</span>
                            Click a quantity to type a count, Enter to finish
                        </li>
                        <li class="intake-tip">
                            <span class="material-symbols-outlined icon icon--xs">scale</span>
                            Per-gram stones are received in g or kg
                        </li>
                        <li class="intake-tip">
                            <span class="material-symbols-outlined icon icon--xs">sell</span>
                            Different rate or carat? Use New lot
                        </li>
                    </ul>
                </div>
            </section>

            {{-- The batch being received. --}}
            <section class="panel intake-batch">
                <div class="panel__header">
                    <div>
                        <h2 class="panel__title">Batch</h2>
                        <p class="panel__subtitle">Stones that will be added to stock when you save.</p>
                    </div>
                    <span class="badge badge--inactive" data-batch-count>0 stones</span>
                </div>

                <div class="intake-empty" data-batch-empty>
                    <span class="intake-empty__icon" aria-hidden="true">
                        <span class="material-symbols-outlined icon icon--lg">inventory_2</span>
                    </span>
                    <p class="intake-empty__title">No stones in this batch yet</p>
                    <p class="intake-empty__hint">Scan a packet or search by name above to start.</p>
                </div>

                <div class="panel__scroll is-hidden" data-batch-table>
                    <table class="table-admin intake-table">
                        <thead>
                            <tr>
                                <th>Stone</th>
                                <th>Shape &middot; size</th>
                                <th class="table-admin__num">Carat</th>
                                <th>Quantity</th>
                                <th class="table-admin__actions-col"></th>
                            </tr>
                        </thead>
                        <tbody data-scan-rows></tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- Summary and save. --}}
        <aside class="intake__side">
            <section class="panel intake-summary">
                <div class="panel__header">
                    <h2 class="panel__title">Batch summary</h2>
                </div>
                <div class="panel__body">
                    <div class="intake-stats" data-totals>
                        <div class="intake-stat">
                            <span class="intake-stat__label">Stone types</span>
                            <strong class="intake-stat__value" data-total-types>0</strong>
                        </div>
                        <div class="intake-stat intake-stat--primary">
                            <span class="intake-stat__label">Pieces</span>
                            <strong class="intake-stat__value" data-total-qty>0</strong>
                        </div>
                        <div class="intake-stat">
                            <span class="intake-stat__label">Carat</span>
                            <strong class="intake-stat__value" data-total-carat>0</strong>
                        </div>
                        <div class="intake-stat">
                            <span class="intake-stat__label">Grams</span>
                            <strong class="intake-stat__value" data-total-grams>0</strong>
                        </div>
                    </div>

                    <div class="intake-summary__fields">
                        <div class="form-field">
                            <label class="form-label" for="intake_date">Intake date</label>
                            <input id="intake_date" type="date" class="input-field" data-intake-date value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}">
                        </div>
                        <div class="form-field">
                            <label class="form-label" for="intake_notes">Notes</label>
                            <textarea id="intake_notes" class="input-field" rows="3" data-intake-notes maxlength="2000" placeholder="Supplier, parcel, invoice no."></textarea>
                        </div>
                        <x-btn type="button" class="btn--block" data-save-intake loading-text="Saving...">
                            <span class="material-symbols-outlined icon icon--sm">task_alt</span>
                            Save intake
                        </x-btn>
                        <p class="intake-summary__note">
                            <span class="material-symbols-outlined icon icon--xs">cloud_done</span>
                            Drafts save automatically. A saved intake cannot be edited.
                        </p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>

@if($canRegister)
<x-dialog
    id="intake-register-modal"
    title="Register new lot"
    subtitle="Stock bought at a different rate or carat is its own lot. Its opening stock is counted when you register, so it is not added to this batch."
    icon="diamond"
    size="xl"
>
    <form id="intake-register-form" class="dialog__form" data-validate-form>
        @csrf
        <div class="dialog__body">
            @include('branch.stones._form')
        </div>
        <div class="dialog__footer">
            <span class="dialog__hint">
                <span class="material-symbols-outlined icon icon--xs">barcode_scanner</span>
                A packet barcode is issued when you register.
            </span>
            <x-btn type="button" variant="secondary" data-modal-close>Cancel</x-btn>
            <x-btn type="submit" loading-text="Saving...">
                <span class="material-symbols-outlined icon icon--sm">add_circle</span>
                Register lot
            </x-btn>
        </div>
    </form>
</x-dialog>
@endif
@endsection
