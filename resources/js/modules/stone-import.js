/**
 * Stone Excel import wizard.
 *
 * Drives the three steps in resources/views/branch/stones/_import-modal.blade.php:
 *   upload  -> POST {stage-url}   returns columns + a suggested mapping
 *   map     -> POST {preview-url} returns every row, costed, with its problems
 *   preview -> POST {store-url}   inserts the rows the operator approved
 *
 * All three answer the project envelope: { success, message, data, errors }.
 */

/** Columns shown in the preview grid, in order, and how each is edited. */
const PREVIEW_COLUMNS = [
    { key: 'name', wide: true },
    { key: 'shape' },
    { key: 'dimension' },
    { key: 'carat' },
    { key: 'pieces' },
    { key: 'pairs' },
    { key: 'weight_grams' },
    {
        key: 'pricing_mode',
        options: [
            ['', '—'],
            ['per_carat', 'Per carat'],
            ['per_piece', 'Per piece'],
            ['per_gram', 'Per gram'],
        ],
    },
    { key: 'price_per_carat' },
    { key: 'price_per_piece' },
    { key: 'price_per_gram' },
    {
        key: 'status',
        options: [
            ['in_stock', 'In stock'],
            ['pending', 'Pending'],
        ],
    },
    { key: 'tray_no' },
    { key: 'image_code' },
];

/**
 * CSRF token from the layout meta tag.
 *
 * @returns {string}
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/**
 * POST JSON or FormData and unwrap the envelope.
 *
 * @param {string} url
 * @param {object|FormData} body
 * @returns {Promise<{ok: boolean, json: object}>}
 */
async function post(url, body) {
    const isForm = body instanceof FormData;
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
    };
    if (!isForm) {
        headers['Content-Type'] = 'application/json';
    }

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers,
            body: isForm ? body : JSON.stringify(body),
        });
        const json = await response.json().catch(() => ({}));

        return { ok: response.ok, json };
    } catch (error) {
        // A dropped connection must not leave the wizard stuck on a spinner.
        return { ok: false, json: { message: 'The server could not be reached. Check your connection and try again.' } };
    }
}

/**
 * First readable message from an envelope, for the SweetAlert popup.
 *
 * @param {object} json
 * @param {string} fallback
 * @returns {string}
 */
function messageOf(json, fallback) {
    const firstError = Object.values(json?.errors || {})[0];

    return (Array.isArray(firstError) ? firstError[0] : firstError) || json?.message || fallback;
}

/**
 * POST with upload progress, for the file upload step.
 *
 * fetch cannot report how much of a body has been sent, so this uses
 * XMLHttpRequest. The returned abort() lets Cancel stop a slow upload.
 *
 * @param {string} url
 * @param {FormData} body
 * @param {(percent: number) => void} onProgress Called with 0-100 while sending
 * @returns {{promise: Promise<{ok: boolean, json: object, aborted: boolean}>, abort: () => void}}
 */
function postWithProgress(url, body, onProgress) {
    const xhr = new XMLHttpRequest();

    const promise = new Promise((resolve) => {
        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());

        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        });
        xhr.addEventListener('load', () => {
            let json = {};
            try {
                json = JSON.parse(xhr.responseText);
            } catch (error) {
                json = {};
            }
            resolve({ ok: xhr.status >= 200 && xhr.status < 300, json, aborted: false });
        });
        xhr.addEventListener('error', () => resolve({
            ok: false,
            json: { message: 'The server could not be reached. Check your connection and try again.' },
            aborted: false,
        }));
        xhr.addEventListener('abort', () => resolve({ ok: false, json: {}, aborted: true }));

        xhr.send(body);
    });

    return { promise, abort: () => xhr.abort() };
}

/**
 * Seconds as m:ss (or h:mm:ss).
 *
 * @param {number} totalSeconds
 * @returns {string}
 */
function formatDuration(totalSeconds) {
    const seconds = Math.max(0, Math.round(totalSeconds));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = String(seconds % 60).padStart(2, '0');

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}`
        : `${minutes}:${rest}`;
}

/**
 * The small progress dialog shown while rows are inserted: a 0-100% bar,
 * rows done, time taken so far, and a rough time left.
 */
class InsertProgress {
    /**
     * @param {HTMLElement} root The [data-progress-modal] element
     */
    constructor(root) {
        // Same stacking rule as the import dialog: it must live on <body>.
        if (root.parentElement !== document.body) {
            document.body.appendChild(root);
        }

        this.root = root;
        this.title = root.querySelector('[data-progress-title]');
        this.fill = root.querySelector('[data-progress-fill]');
        this.percentText = root.querySelector('[data-progress-percent]');
        this.rowsText = root.querySelector('[data-progress-rows]');
        this.elapsedText = root.querySelector('[data-progress-elapsed]');
        this.etaText = root.querySelector('[data-progress-eta]');
        this.message = root.querySelector('[data-progress-message]');
        this.actions = root.querySelector('[data-progress-actions]');
        this.historyLink = root.querySelector('[data-progress-history]');
        this.doneButton = root.querySelector('[data-progress-done]');

        this.stonesUrl = root.dataset.stonesUrl;
        this.timer = null;

        this.doneButton?.addEventListener('click', () => {
            window.location.href = this.stonesUrl;
        });
    }

    /**
     * Open at 0% and start the clock.
     *
     * @param {number} total Rows that will be inserted
     */
    start(total) {
        this.total = total;
        this.startedAt = Date.now();
        this.done = 0;

        this.title.textContent = 'Importing stones';
        this.message.textContent = 'Please keep this page open until it finishes.';
        this.message.classList.remove('is-error');
        this.actions.classList.add('is-hidden');
        this.historyLink.classList.add('is-hidden');
        this.root.classList.remove('is-hidden');
        this.paint();

        window.clearInterval(this.timer);
        this.timer = window.setInterval(() => this.paint(), 250);
    }

    /**
     * Record how many rows have been handled so far.
     *
     * @param {number} done
     */
    update(done) {
        this.done = Math.min(done, this.total);
        this.paint();
    }

    /**
     * Redraw bar, counts, and the clock.
     */
    paint() {
        const percent = this.total > 0 ? Math.floor((this.done / this.total) * 100) : 0;
        const elapsed = (Date.now() - this.startedAt) / 1000;

        this.fill.style.width = `${percent}%`;
        this.percentText.textContent = `${percent}%`;
        this.rowsText.textContent = `${this.done.toLocaleString()} of ${this.total.toLocaleString()}`;
        this.elapsedText.textContent = formatDuration(elapsed);

        // Rate so far gives a fair guess; with nothing done yet there is none.
        this.etaText.textContent = this.done > 0 && this.done < this.total
            ? `about ${formatDuration((elapsed / this.done) * (this.total - this.done))}`
            : '—';
    }

    /**
     * Stop the clock and show the outcome.
     *
     * @param {{title: string, message: string, error?: boolean, history?: boolean, percent?: number}} outcome
     */
    finish({ title, message, error = false, history = false }) {
        window.clearInterval(this.timer);
        this.timer = null;

        if (!error) {
            this.done = this.total;
        }
        this.paint();
        this.etaText.textContent = '—';

        this.title.textContent = title;
        this.message.textContent = message;
        this.message.classList.toggle('is-error', error);
        this.historyLink.classList.toggle('is-hidden', !history);
        this.actions.classList.remove('is-hidden');
    }
}

/**
 * One import dialog and its state machine.
 */
class StoneImport {
    /**
     * @param {HTMLElement} modal The .stone-import overlay
     */
    constructor(modal) {
        // The page content column is its own stacking context, so an overlay
        // left inside it renders underneath the sidebar and topbar however
        // high its z-index. Moving it to <body> is the only reliable fix.
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }

        this.modal = modal;
        this.dialog = modal.querySelector('.stone-import__dialog');
        this.urls = {
            stage: this.dialog.dataset.stageUrl,
            preview: this.dialog.dataset.previewUrl,
            recheck: this.dialog.dataset.recheckUrl,
            begin: this.dialog.dataset.beginUrl,
            chunk: this.dialog.dataset.chunkUrl,
            finish: this.dialog.dataset.finishUrl,
        };

        const progressRoot = document.querySelector('[data-progress-modal]');
        this.progress = progressRoot ? new InsertProgress(progressRoot) : null;

        this.fileInput = modal.querySelector('[data-import-file]');
        this.dropZone = modal.querySelector('[data-import-drop]');
        this.mapHost = modal.querySelector('[data-import-map]');
        this.rowsHost = modal.querySelector('[data-import-rows]');
        this.summary = modal.querySelector('[data-import-summary]');
        this.statusLine = modal.querySelector('[data-import-status]');
        this.heading = modal.querySelector('[data-import-heading]');
        this.nextBtn = modal.querySelector('[data-import-next]');
        this.backBtn = modal.querySelector('[data-import-back]');
        this.insertBtn = modal.querySelector('[data-import-insert]');

        this.reset();
        this.bind();
    }

    /**
     * Clear every trace of a previous run so reopening never shows stale data.
     */
    reset() {
        this.step = 'upload';
        this.file = null;
        this.token = null;
        this.columns = [];
        this.fields = {};
        this.mapping = {};
        this.rows = [];
        this.rowCount = 0;
        this.busy = false;
        // Bumped on every reset so an answer that arrives after the dialog
        // was closed or reopened is ignored instead of redrawing it.
        this.runId = (this.runId || 0) + 1;
        this.activeRequest?.abort();
        this.activeRequest = null;

        if (this.fileInput) {
            this.fileInput.value = '';
        }
        this.mapHost.innerHTML = '';
        this.rowsHost.innerHTML = '';
        this.summary.innerHTML = '';
        this.setStatus('');
        this.modal.querySelector('[data-import-filename]')?.classList.add('is-hidden');
        this.render();
    }

    /**
     * Wire the buttons, the drop zone, and the file picker.
     */
    bind() {
        document.querySelectorAll('[data-import-open]').forEach((button) => {
            button.addEventListener('click', () => this.open());
        });

        this.modal.querySelector('[data-import-close]')?.addEventListener('click', () => this.close());
        this.modal.querySelector('[data-import-cancel]')?.addEventListener('click', () => this.close());
        this.modal.addEventListener('click', (event) => {
            if (event.target === this.modal) {
                this.close();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !this.modal.classList.contains('is-hidden')) {
                this.close();
            }
        });

        this.dropZone?.addEventListener('click', () => this.fileInput?.click());
        this.dropZone?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                this.fileInput?.click();
            }
        });

        ['dragenter', 'dragover'].forEach((type) => {
            this.dropZone?.addEventListener(type, (event) => {
                event.preventDefault();
                this.dropZone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach((type) => {
            this.dropZone?.addEventListener(type, (event) => {
                event.preventDefault();
                this.dropZone.classList.remove('is-dragging');
            });
        });
        this.dropZone?.addEventListener('drop', (event) => {
            this.acceptFile(event.dataTransfer?.files?.[0]);
        });

        this.fileInput?.addEventListener('change', () => this.acceptFile(this.fileInput.files?.[0]));

        this.nextBtn?.addEventListener('click', () => this.advance());
        this.backBtn?.addEventListener('click', () => this.goBack());
        this.insertBtn?.addEventListener('click', () => this.insert());
    }

    /** Show the dialog on a fresh upload step. */
    open() {
        this.reset();
        this.modal.classList.remove('is-hidden');
        // Stop the page behind the overlay scrolling with the preview grid.
        document.body.style.overflow = 'hidden';
    }

    /** Hide the dialog. The staged file expires on the server on its own. */
    close() {
        // Leaving mid-upload stops it; a late answer is dropped via runId.
        this.activeRequest?.abort();
        this.activeRequest = null;
        this.runId += 1;
        this.busy = false;

        this.modal.classList.add('is-hidden');
        document.body.style.overflow = '';
    }

    /**
     * Remember the chosen file and enable Next.
     *
     * @param {File|undefined} file
     */
    acceptFile(file) {
        if (!file) {
            return;
        }

        this.file = file;
        const label = this.modal.querySelector('[data-import-filename]');
        label?.classList.remove('is-hidden');
        this.modal.querySelector('[data-import-filename-text]').textContent = file.name;
        this.nextBtn.disabled = false;
    }

    /**
     * Move forward one step, doing that step's request first.
     */
    async advance() {
        if (this.step === 'upload') {
            await this.stage();
        } else if (this.step === 'map') {
            await this.loadPreview();
        }
    }

    /**
     * Step back without losing what was already read.
     */
    goBack() {
        this.step = this.step === 'preview' ? 'map' : 'upload';
        this.render();
    }

    /**
     * Upload the workbook and build the mapping form from its columns.
     *
     * The dialog swaps the drop zone for a progress panel: a real percentage
     * while the file is sent, then a moving bar while the server reads it.
     */
    async stage() {
        const runId = this.runId;
        const body = new FormData();
        body.append('file', this.file);

        this.showBusy({ title: 'Uploading file', detail: this.file.name, percent: 0 });

        const request = postWithProgress(this.urls.stage, body, (percent) => {
            if (runId !== this.runId) {
                return;
            }

            if (percent >= 100) {
                this.showBusy({ title: 'Reading spreadsheet', detail: 'Finding the columns…', percent: null });
            } else {
                this.showBusy({ title: 'Uploading file', detail: `${this.file.name} — ${percent}%`, percent });
            }
        });
        this.activeRequest = request;

        const { ok, json, aborted } = await request.promise;
        if (aborted || runId !== this.runId) {
            return;
        }
        this.activeRequest = null;
        this.hideBusy();

        if (!ok) {
            window.AnnigemsAlert?.notifyError(messageOf(json, 'That file could not be read.'));
            return;
        }

        this.token = json.data.token;
        this.columns = json.data.columns || [];
        this.fields = json.data.fields || {};
        this.mapping = json.data.mapping || {};
        this.rowCount = json.data.row_count || 0;

        this.renderMapping();
        this.setStatus(`${json.data.row_count} rows found in ${json.data.filename}.`);
        this.step = 'map';
        this.render();
    }

    /**
     * Swap the current step for the progress panel.
     *
     * @param {{title: string, detail?: string, percent: number|null}} state
     *        percent is 0-100, or null when the length of the wait is unknown
     */
    showBusy({ title, detail = '', percent }) {
        this.busy = true;

        this.modal.querySelector('[data-busy-title]').textContent = title;
        this.modal.querySelector('[data-busy-detail]').textContent = detail;

        const bar = this.modal.querySelector('[data-busy-bar]');
        const fill = this.modal.querySelector('[data-busy-fill]');
        bar.classList.toggle('stone-bar--indeterminate', percent === null);
        fill.style.width = percent === null ? '' : `${percent}%`;

        this.render();
    }

    /**
     * Return to the step that was showing before the progress panel.
     */
    hideBusy() {
        this.busy = false;
        this.render();
    }

    /**
     * Draw one dropdown per field, preselected with the auto-matched column.
     */
    renderMapping() {
        this.mapHost.innerHTML = '';

        Object.entries(this.fields).forEach(([key, meta]) => {
            const wrap = document.createElement('label');
            wrap.className = 'stone-import__map-field';

            const label = document.createElement('span');
            label.className = `stone-import__map-label${meta.required ? ' stone-import__map-label--required' : ''}`;
            label.textContent = meta.label;

            const select = document.createElement('select');
            select.className = 'input-field';
            select.dataset.mapField = key;
            select.dataset.noSelect2 = 'true';

            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = 'Not in this sheet';
            select.appendChild(blank);

            this.columns.forEach((column, index) => {
                const option = document.createElement('option');
                option.value = String(index);
                option.textContent = column || `Column ${index + 1}`;
                select.appendChild(option);
            });

            const matched = this.mapping[key];
            select.value = matched === null || matched === undefined ? '' : String(matched);
            select.addEventListener('change', () => {
                this.mapping[key] = select.value === '' ? null : Number(select.value);
            });

            wrap.append(label, select);
            this.mapHost.appendChild(wrap);
        });
    }

    /**
     * Cost every row with the confirmed mapping and show the preview grid.
     */
    async loadPreview() {
        const runId = this.runId;

        this.showBusy({
            title: 'Calculating rows',
            detail: `Costing ${this.rowCount.toLocaleString()} rows with your column choices…`,
            percent: null,
        });
        const { ok, json } = await post(this.urls.preview, { token: this.token, mapping: this.mapping });
        if (runId !== this.runId) {
            return;
        }
        this.hideBusy();

        if (!ok) {
            window.AnnigemsAlert?.notifyError(messageOf(json, 'Those columns could not be processed.'));
            return;
        }

        this.rows = json.data.rows || [];
        this.renderPreview(json.data.ok, json.data.errors);
        this.step = 'preview';
        this.render();
    }

    /**
     * Draw the editable grid. Green rows are ready; red rows need a fix.
     *
     * @param {number} okCount
     * @param {number} errorCount
     */
    renderPreview(okCount, errorCount) {
        this.summary.innerHTML = '';
        const ok = document.createElement('span');
        ok.className = 'stone-import__chip stone-import__chip--ok';
        ok.textContent = `${okCount} ready`;
        const bad = document.createElement('span');
        bad.className = 'stone-import__chip stone-import__chip--error';
        bad.textContent = `${errorCount} need a fix`;
        this.summary.append(ok, bad);

        // A supplier sheet runs to thousands of rows, so the grid is built
        // off-document and attached once instead of reflowing per row.
        const fragment = document.createDocumentFragment();
        this.rows.forEach((row, index) => fragment.appendChild(this.buildRow(row, index)));

        this.rowsHost.innerHTML = '';
        this.rowsHost.appendChild(fragment);

        this.insertBtn.disabled = okCount === 0;
        this.insertBtn.textContent = errorCount > 0 ? `Insert ${okCount} ready rows` : 'Insert';
        this.setStatus(
            errorCount > 0
                ? `${okCount} rows will be inserted. ${errorCount} red rows will be kept in Stones > Import History to download and fix.`
                : 'Every row calculated. Review and insert.'
        );
    }

    /**
     * Build one editable preview row.
     *
     * @param {object} row
     * @param {number} index Position in this.rows
     * @returns {HTMLTableRowElement}
     */
    buildRow(row, index) {
        const tr = document.createElement('tr');
        const broken = (row.issues || []).length > 0;
        tr.className = broken ? 'stone-import__row--error' : 'stone-import__row--ok';

        const rowNumber = document.createElement('td');
        rowNumber.textContent = row.sheet_row ?? index + 1;
        tr.appendChild(rowNumber);

        PREVIEW_COLUMNS.forEach((column) => {
            const td = document.createElement('td');
            const field = column.options
                ? this.buildSelectCell(column, row)
                : this.buildTextCell(column, row);

            // Editing re-costs the row server-side so the colour never lies.
            field.addEventListener('change', () => {
                this.rows[index][column.key] = field.value;
                this.refresh();
            });

            td.appendChild(field);
            tr.appendChild(td);
        });

        const calc = document.createElement('td');
        calc.className = 'stone-import__calc';
        calc.textContent = row.cost_formula || '—';
        if (row.mismatch) {
            calc.textContent += ` (sheet said ${row.sheet_total})`;
        }
        tr.appendChild(calc);

        const issues = document.createElement('td');
        issues.className = 'stone-import__issues';
        issues.textContent = (row.issues || []).join(' ');
        tr.appendChild(issues);

        return tr;
    }

    /**
     * Free-text cell.
     *
     * @param {{key: string, wide?: boolean}} column
     * @param {object} row
     * @returns {HTMLInputElement}
     */
    buildTextCell(column, row) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = `stone-import__cell${column.wide ? ' stone-import__cell--wide' : ''}`;
        input.value = row[column.key] ?? '';

        return input;
    }

    /**
     * Fixed-choice cell (pricing mode, status).
     *
     * @param {{key: string, options: Array<[string, string]>}} column
     * @param {object} row
     * @returns {HTMLSelectElement}
     */
    buildSelectCell(column, row) {
        const select = document.createElement('select');
        select.className = 'stone-import__cell';
        select.dataset.noSelect2 = 'true';

        column.options.forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            select.appendChild(option);
        });

        // A mode the sheet spelled its own way is not in the list; keep it
        // visible so the operator can see what needs replacing.
        const current = row[column.key] ?? '';
        if (current !== '' && !column.options.some(([value]) => value === current)) {
            const option = document.createElement('option');
            option.value = current;
            option.textContent = current;
            select.appendChild(option);
        }
        select.value = current;

        return select;
    }

    /**
     * Re-cost the edited rows with the same server rules used at insert, so
     * a row turns green the moment the operator actually fixes it.
     */
    async refresh() {
        const { ok, json } = await post(this.urls.recheck, { rows: this.rows });
        if (!ok || !json?.data?.rows) {
            return;
        }

        this.rows = json.data.rows;
        this.renderPreview(json.data.ok, json.data.errors);
    }

    /**
     * Insert the rows that pass, a batch at a time, behind a progress dialog.
     *
     * The import dialog closes when Insert is confirmed. The rows are sent in
     * batches so the bar reflects real work; rows still red (or rejected by a
     * batch's final check) are collected and stored by the finish call as the
     * rows-to-fix file in Import History.
     */
    async insert() {
        const ready = this.rows.filter((row) => (row.issues || []).length === 0);
        const bad = this.rows.filter((row) => (row.issues || []).length > 0);

        const confirmed = await window.AnnigemsAlert?.confirmAction({
            title: `Import ${ready.length} stones?`,
            text: bad.length > 0
                ? `Each ready row becomes a stone lot. ${bad.length} rows with problems will not be inserted; download them later from Stones > Import History.`
                : 'Each row becomes a stone lot with its own code and barcode.',
            confirmButtonText: 'Insert',
        });
        if (!confirmed) {
            return;
        }

        const token = this.token;
        const headers = this.sheetHeaders();
        const mapping = { ...this.mapping };
        const filename = this.file?.name || '';

        this.close();
        this.progress?.start(ready.length);

        // Closing the tab mid-way would leave the import half done.
        const warn = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);

        try {
            await this.runInsert({ ready, bad, token, headers, mapping, filename });
        } finally {
            window.removeEventListener('beforeunload', warn);
        }
    }

    /**
     * The begin, batches, finish sequence behind insert().
     *
     * @param {{ready: object[], bad: object[], token: string, headers: object, mapping: object, filename: string}} job
     */
    async runInsert({ ready, bad, token, headers, mapping, filename }) {
        const stop = (message, history = false) => this.progress?.finish({
            title: 'Import stopped',
            message,
            error: true,
            history,
        });

        const begun = await post(this.urls.begin, {
            filename,
            total_rows: ready.length + bad.length,
        });
        if (!begun.ok) {
            stop(messageOf(begun.json, 'The import could not be started.'));
            return;
        }

        const importUuid = begun.json.data.import_uuid;
        const chunkSize = Math.max(1, Number(begun.json.data.chunk_size) || 100);
        const rejected = [...bad];
        let processed = 0;

        for (let index = 0; index * chunkSize < ready.length; index += 1) {
            const rows = ready.slice(index * chunkSize, (index + 1) * chunkSize);
            const { ok, json } = await post(this.urls.chunk, {
                import_uuid: importUuid,
                chunk_index: index,
                rows,
            });

            if (!ok) {
                stop(
                    `${messageOf(json, 'A batch could not be inserted.')} Rows already inserted stay in your stock; see Import History.`,
                    true
                );
                return;
            }

            rejected.push(...(json.data.rejected || []));
            processed += rows.length;
            this.progress?.update(processed);
        }

        const finished = await post(this.urls.finish, {
            import_uuid: importUuid,
            token,
            headers,
            mapping,
            rejected_rows: rejected,
        });
        if (!finished.ok) {
            stop(
                `${messageOf(finished.json, 'The import could not be closed.')} The rows were inserted; see Import History.`,
                true
            );
            return;
        }

        const { inserted, rejected: notInserted } = finished.json.data;
        const seconds = formatDuration((Date.now() - this.progress.startedAt) / 1000);
        this.progress?.finish({
            title: 'Import finished',
            message: notInserted > 0
                ? `${inserted.toLocaleString()} rows inserted in ${seconds}. ${notInserted.toLocaleString()} rows were not inserted: download them from Import History, fix them, and import again.`
                : `${inserted.toLocaleString()} rows inserted in ${seconds}.`,
            history: notInserted > 0,
        });
    }

    /**
     * The sheet's own header text for each mapped field, so the rejects file
     * keeps the operator's column names.
     *
     * @returns {Object<string, string>}
     */
    sheetHeaders() {
        const headers = {};
        Object.entries(this.mapping).forEach(([field, index]) => {
            if (index !== null && index !== undefined && this.columns[index]) {
                headers[field] = this.columns[index];
            }
        });

        return headers;
    }

    /**
     * Footer hint text.
     *
     * @param {string} text
     */
    setStatus(text) {
        this.statusLine.textContent = text;
    }

    /**
     * Show the active step and the buttons that belong to it.
     */
    render() {
        // While busy only the progress panel is shown, whatever the step.
        const shown = this.busy ? 'busy' : this.step;
        this.modal.querySelectorAll('[data-step]').forEach((section) => {
            section.classList.toggle('is-hidden', section.dataset.step !== shown);
        });

        const headings = {
            upload: 'Upload Excel',
            map: 'Confirm columns',
            preview: 'Review rows before inserting',
        };
        this.heading.textContent = headings[this.step];

        this.backBtn.classList.toggle('is-hidden', this.step === 'upload' || this.busy);
        this.nextBtn.classList.toggle('is-hidden', this.step === 'preview' || this.busy);
        this.insertBtn.classList.toggle('is-hidden', this.step !== 'preview' || this.busy);

        if (this.step === 'upload') {
            this.nextBtn.disabled = this.file === null;
        } else if (this.step === 'map') {
            this.nextBtn.disabled = false;
        }
    }
}

/**
 * Page boot for the stone import modal.
 */
export function bindStoneImport() {
    document.querySelectorAll('[data-import-modal]').forEach((modal) => new StoneImport(modal));
}
