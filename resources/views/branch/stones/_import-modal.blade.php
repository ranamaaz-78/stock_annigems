{{--
    Excel import wizard for stone lots.

    Three steps live in this one dialog and are shown one at a time by
    resources/js/modules/stone-import.js:
      1. upload  — click or drag-drop the workbook
      2. map     — confirm which sheet column feeds which of our fields
      3. preview — every costed row, editable; green is inserted, red rows are kept in Import History to fix
--}}
<div class="stone-import is-hidden" data-import-modal role="dialog" aria-modal="true" aria-labelledby="stone-import-title">
    <div class="stone-import__dialog"
         data-stage-url="{{ route('branch.stones.import.stage') }}"
         data-preview-url="{{ route('branch.stones.import.preview') }}"
         data-recheck-url="{{ route('branch.stones.import.recheck') }}"
         data-begin-url="{{ route('branch.stones.import.begin') }}"
         data-chunk-url="{{ route('branch.stones.import.chunk') }}"
         data-finish-url="{{ route('branch.stones.import.finish') }}">

        <header class="stone-import__header">
            <h2 class="stone-import__title" id="stone-import-title" data-import-heading>Upload Excel</h2>
            <button type="button" class="stone-import__close" data-import-close aria-label="Close">
                <span class="material-symbols-outlined icon icon--sm">close</span>
            </button>
        </header>

        <div class="stone-import__body">
            {{-- Working: shown while a file uploads or rows are being costed. --}}
            <section class="stone-import__step is-hidden" data-step="busy">
                <div class="stone-busy">
                    <span class="material-symbols-outlined icon icon--lg stone-busy__icon">upload_file</span>
                    <p class="stone-busy__title" data-busy-title>Working…</p>
                    <div class="stone-bar" data-busy-bar>
                        <div class="stone-bar__fill" data-busy-fill></div>
                    </div>
                    <p class="stone-busy__detail" data-busy-detail></p>
                </div>
            </section>

            {{-- Step 1: file selection --}}
            <section class="stone-import__step" data-step="upload">
                <div class="stone-import__drop" data-import-drop tabindex="0" role="button">
                    <span class="material-symbols-outlined icon icon--lg">upload_file</span>
                    <span class="stone-import__drop-title">Drop the inventory sheet here</span>
                    <span class="stone-import__drop-hint">or click to choose a file — .xlsx, .xls, or .csv</span>
                </div>
                <input type="file" class="is-hidden" data-import-file accept=".xlsx,.xls,.csv" hidden>
                <div class="stone-import__file is-hidden" data-import-filename>
                    <span class="material-symbols-outlined icon icon--sm">description</span>
                    <span data-import-filename-text></span>
                </div>
            </section>

            {{-- Step 2: column confirmation --}}
            <section class="stone-import__step is-hidden" data-step="map">
                <p class="text-body-sm mb-3">
                    Each field below is matched to a column from your sheet. Change any that picked the wrong one.
                </p>
                <div class="stone-import__map" data-import-map></div>
            </section>

            {{-- Step 3: editable preview --}}
            <section class="stone-import__step is-hidden" data-step="preview">
                <div class="stone-import__summary" data-import-summary></div>
                <div class="panel__scroll">
                    <table class="stone-import__grid">
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Name</th>
                                <th>Shape</th>
                                <th>Dimension</th>
                                <th>Carat</th>
                                <th>Pcs</th>
                                <th>Pairs</th>
                                <th>Grams</th>
                                <th>Mode</th>
                                <th>Per carat</th>
                                <th>Per piece</th>
                                <th>Per gram</th>
                                <th>Status</th>
                                <th>Tray</th>
                                <th>Image code</th>
                                <th>Calculation</th>
                                <th>Problem</th>
                            </tr>
                        </thead>
                        <tbody data-import-rows></tbody>
                    </table>
                </div>
            </section>
        </div>

        <footer class="stone-import__footer">
            <span class="text-body-sm" data-import-status></span>
            <div class="stone-import__footer-actions">
                <button type="button" class="btn btn--secondary is-hidden" data-import-back>Back</button>
                <button type="button" class="btn btn--secondary" data-import-cancel>Cancel</button>
                <button type="button" class="btn btn--primary" data-import-next disabled>Next</button>
                <button type="button" class="btn btn--excel is-hidden" data-import-insert>Insert</button>
            </div>
        </footer>
    </div>
</div>

{{--
    Insert progress. The import dialog closes when Insert is confirmed and
    this small one takes over: a 0-100% bar, rows done, time taken, and a
    rough time left. It stays until the import has finished.
--}}
<div class="stone-progress is-hidden" data-progress-modal role="dialog" aria-modal="true" aria-labelledby="stone-progress-title"
     data-stones-url="{{ route('branch.stones.index') }}"
     data-history-url="{{ route('branch.stone-imports.index') }}">
    <div class="stone-progress__dialog">
        <h2 class="stone-progress__title" id="stone-progress-title" data-progress-title>Importing stones</h2>

        <div class="stone-bar">
            <div class="stone-bar__fill" data-progress-fill></div>
        </div>
        <p class="stone-progress__percent" data-progress-percent>0%</p>

        <dl class="stone-progress__stats">
            <div>
                <dt>Rows</dt>
                <dd data-progress-rows>0 of 0</dd>
            </div>
            <div>
                <dt>Time taken</dt>
                <dd data-progress-elapsed>0:00</dd>
            </div>
            <div>
                <dt>Time left</dt>
                <dd data-progress-eta>—</dd>
            </div>
        </dl>

        <p class="stone-progress__message" data-progress-message>Please keep this page open until it finishes.</p>

        <div class="stone-progress__actions is-hidden" data-progress-actions>
            <a href="{{ route('branch.stone-imports.index') }}" class="btn btn--secondary is-hidden" data-progress-history>Import History</a>
            <button type="button" class="btn btn--primary" data-progress-done>Done</button>
        </div>
    </div>
</div>
