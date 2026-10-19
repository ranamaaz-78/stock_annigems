/**
 * Stone intake scan workspace: USB wedge scanner, lookup queue, server draft, save.
 */

import { attachBarcodeScanner } from './barcode-scanner';
import { t } from './i18n';

/**
 * CSRF token from the layout meta tag.
 *
 * @returns {string}
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/**
 * JSON request helper using the project envelope.
 *
 * @param {string} url
 * @param {RequestInit} [options]
 * @returns {Promise<{ok: boolean, status: number, json: object}>}
 */
async function fetchJson(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
        ...(options.headers || {}),
    };
    if (options.body && !(options.body instanceof FormData) && !headers['Content-Type']) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, { ...options, headers });
    const json = await response.json().catch(() => ({}));

    return { ok: response.ok, status: response.status, json };
}

/**
 * Short success/fail beep. Autoplay policies may silence this.
 *
 * @param {boolean} success
 */
function beep(success) {
    const root = document.getElementById('stone-intake');
    const scanner = JSON.parse(root?.dataset.scanner || '{}');
    if (scanner.beep_enabled === false) {
        return;
    }

    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = success ? 880 : 220;
        gain.gain.value = 0.08;
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + (success ? 0.12 : 0.28));
    } catch (error) {
        // Audio is optional.
    }
}

/**
 * Text safe to put inside innerHTML.
 *
 * @param {unknown} value
 * @returns {string}
 */
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[char]);
}

/**
 * @param {HTMLElement|null} modal
 * @param {boolean} open
 */
function setModalOpen(modal, open) {
    if (!modal) {
        return;
    }
    modal.classList.toggle('hidden', !open);
}

/**
 * Whether a lot is bought and received by weight rather than by piece.
 *
 * @param {object} stone
 * @returns {boolean}
 */
function isGramLot(stone) {
    return stone?.pricing_mode === 'per_gram';
}

/**
 * Grams a gram row holds, three decimals; kilograms are multiplied out.
 * Zero when nothing valid has been typed yet.
 *
 * @param {{entered: string, unit: string}} row
 * @returns {number}
 */
function gramsOf(row) {
    const typed = parseFloat(row.entered);
    if (!Number.isFinite(typed) || typed <= 0) {
        return 0;
    }

    return Math.round(typed * (row.unit === 'kg' ? 1000 : 1) * 1000) / 1000;
}

/**
 * Grams as text without trailing zeros, e.g. 40.5.
 *
 * @param {number} grams
 * @returns {string}
 */
function gramsText(grams) {
    return String(Number(grams.toFixed(3)));
}

/**
 * Bind the intake scan page.
 */
export function bindStoneIntake() {
    const root = document.getElementById('stone-intake');
    if (!root) {
        return;
    }

    const scanInput = root.querySelector('[data-scan-input]');
    const lookupBtn = root.querySelector('[data-lookup-button]');
    const muteBox = root.querySelector('[data-mute-beep]');
    const messageEl = root.querySelector('[data-scan-message]');
    const registerHint = root.querySelector('[data-register-shortcut]');
    const rowsBody = root.querySelector('[data-scan-rows]');
    const dateInput = root.querySelector('[data-intake-date]');
    const notesInput = root.querySelector('[data-intake-notes]');
    const saveBtn = root.querySelector('[data-save-intake]');
    const suggestEl = root.querySelector('[data-suggest]');
    const scanWrap = root.querySelector('.intake-find');
    const registerModal = document.getElementById('intake-register-modal');
    const registerForm = document.getElementById('intake-register-form');

    let scannerConfig = {};
    try {
        scannerConfig = JSON.parse(root.dataset.scanner || '{}');
    } catch (error) {
        scannerConfig = {};
    }
    const debounceMs = Number(root.dataset.debounce || 800);
    const canRegister = root.dataset.canRegister === '1';

    /** @type {Map<string, {stone: object, quantity: number, scanned_count: number}>} */
    const rows = new Map();
    let draftTimer = null;
    let lastUnknown = '';
    let scannerApi = null;
    let searchTimer = null;
    let searchSeq = 0;
    /** @type {object[]} */
    let suggestions = [];
    let activeSuggest = 0;
    const qtyMax = Number(root.dataset.qtyMax || 10000);

    /**
     * Any field except the scan box keeps the keyboard (qty, date, notes, search, modal).
     * Scan capture only intercepts when focus is on the page chrome or the scan input.
     *
     * @param {EventTarget|null} el
     * @returns {boolean}
     */
    function isProtectedTarget(el) {
        if (!(el instanceof HTMLElement)) {
            return false;
        }
        if (el === scanInput) {
            return false;
        }
        if (el.matches('input, textarea, select, [contenteditable="true"]')) {
            return true;
        }
        if (registerModal && !registerModal.classList.contains('hidden') && registerModal.contains(el)) {
            return true;
        }
        return false;
    }

    /**
     * Show or clear the inline scan message.
     *
     * @param {string} text
     */
    function setMessage(text) {
        if (!messageEl) {
            return;
        }
        messageEl.textContent = text;
        messageEl.classList.toggle('is-hidden', !text);
        registerHint?.classList.toggle('hidden', !(text && canRegister));
    }

    /**
     * Recalculate footer totals.
     */
    function renderTotals() {
        let qty = 0;
        let carat = 0;
        let grams = 0;
        rows.forEach((row) => {
            if (isGramLot(row.stone)) {
                grams += gramsOf(row);
                return;
            }
            qty += row.quantity;
            carat += Number(row.stone.carat || 0) * row.quantity;
        });
        const typesEl = root.querySelector('[data-total-types]');
        const qtyEl = root.querySelector('[data-total-qty]');
        const caratEl = root.querySelector('[data-total-carat]');
        if (typesEl) typesEl.textContent = String(rows.size);
        if (qtyEl) qtyEl.textContent = String(qty);
        if (caratEl) caratEl.textContent = carat.toFixed(3);
        const gramsEl = root.querySelector('[data-total-grams]');
        if (gramsEl) gramsEl.textContent = gramsText(grams);

        const countEl = root.querySelector('[data-batch-count]');
        if (countEl) countEl.textContent = `${rows.size} ${rows.size === 1 ? 'stone' : 'stones'}`;
        root.querySelector('[data-batch-empty]')?.classList.toggle('is-hidden', rows.size > 0);
        root.querySelector('[data-batch-table]')?.classList.toggle('is-hidden', rows.size === 0);
    }

    /**
     * Paint the working list from `rows`.
     */
    function renderRows() {
        if (!rowsBody) {
            return;
        }
        rowsBody.innerHTML = '';
        rows.forEach((row) => {
            const gram = isGramLot(row.stone);
            const tr = document.createElement('tr');
            tr.className = 'scan-row--flash';
            tr.dataset.uuid = row.stone.uuid;

            const amountCell = gram
                ? `<div class="intake-weight">
                        <input type="number" min="0.001" step="0.001" inputmode="decimal" class="input-field intake-qty" value="${escapeHtml(row.entered)}" placeholder="${t('grams')}" data-row-weight aria-label="${t('Grams for :name', { name: escapeHtml(row.stone.name) })}">
                        <select class="input-field intake-unit" data-row-unit data-no-select2="true" aria-label="${t('Unit')}">
                            <option value="g"${row.unit === 'g' ? ' selected' : ''}>g</option>
                            <option value="kg"${row.unit === 'kg' ? ' selected' : ''}>${t('kg')}</option>
                        </select>
                   </div>`
                : `<div class="intake-amount">
                        <input type="number" min="1" max="${qtyMax}" step="1" inputmode="numeric" class="input-field intake-qty" value="${row.quantity}" data-row-qty aria-label="Quantity for ${escapeHtml(row.stone.name)}">
                        <span class="intake-amount__unit">${t('pcs')}</span>
                   </div>`;

            const lead = row.stone.image_url
                ? `<span class="intake-thumb"><img src="${escapeHtml(row.stone.image_url)}" alt="" width="40" height="40" loading="lazy"></span>`
                : '<span class="intake-thumb"><span class="material-symbols-outlined icon icon--sm">diamond</span></span>';
            const kind = gram ? '<span class="badge badge--inactive">' + t('Per gram') + '</span>' : '';
            const codes = [row.stone.stone_code, row.stone.barcode].filter(Boolean).map(escapeHtml).join(' &middot; ');
            const shapeSize = [row.stone.shape, row.stone.dimension]
                .filter((part) => part && part !== 'N/A')
                .map(escapeHtml)
                .join(' &middot; ');

            tr.innerHTML = `
                <td>
                    <div class="intake-stone">
                        ${lead}
                        <div class="table-admin__copy">
                            <div class="intake-stone__name">${escapeHtml(row.stone.name)} ${kind}</div>
                            <span class="table-meta">${codes || '—'}</span>
                        </div>
                    </div>
                </td>
                <td>${shapeSize || '—'}</td>
                <td class="table-admin__num font-data-mono">${escapeHtml(row.stone.carat || '—')}</td>
                <td>${amountCell}</td>
                <td class="table-admin__actions-col">
                    <button type="button" class="table-admin__action" data-remove-row aria-label="Remove ${escapeHtml(row.stone.name)}" title="${t('Remove')}">
                        <span class="material-symbols-outlined icon icon--xs">close</span>
                    </button>
                </td>
            `;

            // Enter finishes this stone and hands the keyboard back to the find field.
            const finishOnEnter = (input) => input.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }
                event.preventDefault();
                input.dispatchEvent(new Event('change'));
                scanInput?.focus();
            });

            if (gram) {
                const weightInput = tr.querySelector('[data-row-weight]');
                const unitSelect = tr.querySelector('[data-row-unit]');

                weightInput.addEventListener('input', () => {
                    row.entered = weightInput.value;
                    scheduleDraft();
                    renderTotals();
                });
                unitSelect.addEventListener('change', () => {
                    row.unit = unitSelect.value;
                    scheduleDraft();
                    renderTotals();
                });
                weightInput.addEventListener('focus', () => weightInput.select());
                finishOnEnter(weightInput);
            } else {
                const qtyInput = tr.querySelector('[data-row-qty]');

                // Totals follow every keystroke; the value is only corrected once
                // the field is left, so typing "12" never passes through a forced 1.
                qtyInput.addEventListener('input', () => {
                    const typed = parseInt(qtyInput.value, 10);
                    if (Number.isFinite(typed) && typed >= 1) {
                        row.quantity = Math.min(typed, qtyMax);
                        scheduleDraft();
                        renderTotals();
                    }
                });
                qtyInput.addEventListener('change', () => {
                    const next = Math.min(qtyMax, Math.max(1, parseInt(qtyInput.value, 10) || 1));
                    row.quantity = next;
                    qtyInput.value = String(next);
                    scheduleDraft();
                    renderTotals();
                });
                qtyInput.addEventListener('focus', () => qtyInput.select());
                finishOnEnter(qtyInput);
            }

            tr.querySelector('[data-remove-row]').addEventListener('click', () => {
                rows.delete(row.stone.uuid);
                renderRows();
                scheduleDraft();
                scanInput?.focus();
            });
            rowsBody.appendChild(tr);
        });
        renderTotals();
    }

    /**
     * Put the cursor in a gram row's weight box, so the grams can be typed.
     *
     * @param {string} uuid
     */
    function focusWeight(uuid) {
        const input = rowsBody?.querySelector(`tr[data-uuid="${uuid}"] [data-row-weight]`);
        if (input) {
            input.focus();
            input.select();
        }
    }

    /**
     * Add a stone to the working list.
     *
     * Adding the same stone again, by scan, typing or picking, adds one more
     * to its quantity. The cursor stays in the find field; a different count
     * is typed into the row's quantity box by clicking it. A per-gram lot is
     * received in grams, so its row waits for the weight instead.
     *
     * @param {object} stone
     * @param {boolean} fromScan True for a scanner dump, false for typed or picked
     */
    function addStone(stone, fromScan) {
        const existing = rows.get(stone.uuid);
        const gram = isGramLot(stone);

        if (existing) {
            // A gram lot has no "one more": its grams are typed, not counted.
            if (!gram) {
                existing.quantity = Math.min(qtyMax, existing.quantity + 1);
                if (fromScan) {
                    existing.scanned_count += 1;
                }
            }
        } else {
            rows.set(stone.uuid, {
                stone,
                quantity: gram ? 0 : 1,
                entered: '',
                unit: 'g',
                scanned_count: fromScan && !gram ? 1 : 0,
            });
        }
        renderRows();
        scheduleDraft();
        if (!muteBox?.checked) {
            beep(true);
        }
        setMessage('');
        lastUnknown = '';

        // A gram lot cannot be saved without its grams, so the cursor goes to
        // them. Everything else keeps it in the find field for the next entry.
        if (gram) {
            focusWeight(stone.uuid);
        } else {
            scanInput?.focus();
        }
    }

    /**
     * Look up a code/barcode and add it.
     *
     * @param {string} code
     * @param {boolean} fromScan
     */
    async function lookup(code, fromScan) {
        const trimmed = String(code || '').trim();
        if (!trimmed) {
            setMessage(t('Scan or type a barcode first.'));
            return;
        }

        hideSuggestions();
        setMessage(t('Looking up :code…', { code: trimmed }));

        let ok = false;
        let status = 0;
        let json = {};
        try {
            ({ ok, status, json } = await fetchJson(root.dataset.lookupUrl, {
                method: 'POST',
                body: JSON.stringify({ code: trimmed }),
            }));
        } catch (error) {
            if (!muteBox?.checked) {
                beep(false);
            }
            setMessage(t('Could not look up that barcode. Try again.'));
            return;
        }

        if (ok && json.data?.stone) {
            addStone(json.data.stone, fromScan);
            return;
        }

        if (status === 422 && json.message) {
            // Known stone that cannot be received (deactivated, per-gram).
            if (!muteBox?.checked) {
                beep(false);
            }
            setMessage(json.message);
            return;
        }

        if (!muteBox?.checked) {
            beep(false);
        }
        lastUnknown = trimmed;
        setMessage(json.message || t('Barcode not found.'));
        if (status === 404 && canRegister) {
            registerHint?.classList.remove('hidden');
        }
    }

    /**
     * Debounced draft upsert.
     */
    function scheduleDraft() {
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(saveDraft, debounceMs);
    }

    /**
     * Persist the working list on the server.
     */
    async function saveDraft() {
        const payload = {
            intake_date: dateInput?.value || null,
            notes: notesInput?.value || null,
            beep_muted: Boolean(muteBox?.checked),
            rows: [...rows.values()].map((row) => ({
                uuid: row.stone.uuid,
                quantity: isGramLot(row.stone) ? 0 : row.quantity,
                weight_grams: isGramLot(row.stone) ? gramsOf(row) : null,
                pricing_mode: row.stone.pricing_mode || null,
                scanned_count: row.scanned_count,
                name: row.stone.name,
                stone_code: row.stone.stone_code,
                barcode: row.stone.barcode,
                carat: row.stone.carat,
                shape: row.stone.shape,
                dimension: row.stone.dimension,
            })),
        };
        await fetchJson(root.dataset.draftUrl, {
            method: 'POST',
            body: JSON.stringify(payload),
        });
    }

    /**
     * Rebuild the list from a recovered draft payload.
     *
     * @param {object|null} draft
     */
    function restoreDraft(draft) {
        if (!draft || !Array.isArray(draft.rows) || draft.rows.length === 0) {
            return;
        }
        if (dateInput && draft.intake_date) {
            dateInput.value = draft.intake_date;
        }
        if (notesInput && draft.notes) {
            notesInput.value = draft.notes;
        }
        if (muteBox) {
            muteBox.checked = Boolean(draft.beep_muted);
        }
        draft.rows.forEach((row) => {
            const stone = {
                uuid: row.uuid,
                name: row.name,
                stone_code: row.stone_code,
                barcode: row.barcode,
                carat: row.carat,
                shape: row.shape,
                dimension: row.dimension,
                pricing_mode: row.pricing_mode,
            };
            if (!stone.uuid) {
                return;
            }
            const grams = Number(row.weight_grams || 0);
            rows.set(stone.uuid, {
                stone,
                quantity: isGramLot(stone) ? 0 : Number(row.quantity || 1),
                // A restored gram row shows its grams as typed in g.
                entered: grams > 0 ? gramsText(grams) : '',
                unit: 'g',
                scanned_count: Number(row.scanned_count || 0),
            });
        });
        renderRows();
    }

    /**
     * Confirm and commit the batch.
     */
    async function saveIntake() {
        if (rows.size === 0) {
            window.AnnigemsAlert?.notifyError(t('Scan or add at least one stone before saving.'));
            return;
        }

        // A gram row with no grams cannot be saved; show which one.
        for (const row of rows.values()) {
            if (isGramLot(row.stone) && gramsOf(row) <= 0) {
                window.AnnigemsAlert?.notifyError(t('Enter the grams received for :name.', { name: row.stone.name }));
                focusWeight(row.stone.uuid);
                return;
            }
        }

        let totalQty = 0;
        let totalGrams = 0;
        rows.forEach((row) => {
            if (isGramLot(row.stone)) {
                totalGrams += gramsOf(row);
            } else {
                totalQty += row.quantity;
            }
        });
        const received = [
            totalQty > 0 ? `${totalQty} piece(s)` : '',
            totalGrams > 0 ? `${gramsText(totalGrams)} g` : '',
        ].filter(Boolean).join(' and ');

        const confirmed = await window.AnnigemsAlert?.confirmAction({
            title: t('Save this intake?'),
            text: t(':size stone type(s), :received. This batch cannot be edited later.', { size: rows.size, received: received }),
            confirmButtonText: t('Save intake'),
            icon: 'question',
        });
        if (!confirmed) {
            return;
        }

        const loader = window.AnnigemsButton?.startButtonLoading(saveBtn, saveBtn?.dataset.loadingText || 'Saving...');
        const { ok, json } = await fetchJson(root.dataset.saveUrl, {
            method: 'POST',
            body: JSON.stringify({
                intake_date: dateInput?.value,
                notes: notesInput?.value || null,
                rows: [...rows.values()].map((row) => (isGramLot(row.stone)
                    ? { uuid: row.stone.uuid, weight_grams: gramsOf(row), scanned_count: 0 }
                    : { uuid: row.stone.uuid, quantity: row.quantity, scanned_count: row.scanned_count })),
            }),
        });

        if (!ok) {
            window.AnnigemsAlert?.notifyError(json.message || t('Could not save the intake. The list is unchanged.'));
            loader?.restore();
            return;
        }

        window.AnnigemsAlert?.notifySuccess(json.message || t('Intake saved.'));
        if (json.data?.show_url) {
            window.location.href = json.data.show_url;
        }
    }

    if (scanInput) {
        scannerApi = attachBarcodeScanner({
            input: scanInput,
            config: scannerConfig,
            // A scanner dump counts +1; a code typed here and sent with Enter
            // is a person adding a stone, who then enters its quantity.
            onScan: (code, meta) => lookup(code, !meta?.manual),
            isProtectedTarget,
            readyWrap: root.querySelector('[data-scan-wrap]'),
            enabled: true,
            // Slow typing stays in the field so the name search can use it.
            allowManualTyping: true,
            shouldSubmitScan: () => suggestions.length === 0,
        });
    }

    /**
     * Lookup uses the scanner helper so the key buffer is flushed once
     * (avoids +2 if idle-flush and the button both fire).
     */
    function lookupFromInput() {
        const typed = String(scanInput?.value || '').trim();
        if (!typed) {
            setMessage(t('Scan or type a barcode first.'));
            return;
        }
        if (scannerApi) {
            scannerApi.submitManual();
            return;
        }
        lookup(typed, false);
    }

    lookupBtn?.addEventListener('click', () => {
        // The Add button behaves like Enter: a highlighted suggestion wins.
        if (suggestions.length) {
            pickSuggestion(activeSuggest);
            return;
        }
        lookupFromInput();
    });

    const scanToggle = root.querySelector('[data-scan-toggle]');
    scanToggle?.addEventListener('click', () => {
        const next = !scannerApi?.isEnabled();
        scannerApi?.setEnabled(next);
        scanToggle.classList.toggle('is-active', next);
        scanToggle.setAttribute('aria-pressed', next ? 'true' : 'false');
        scanToggle.textContent = next ? t('Scanning on') : t('Scanning off');
        const state = root.querySelector('[data-scan-state]');
        if (state) state.textContent = next ? t('Ready') : t('Off');
        if (scanInput) {
            scanInput.placeholder = next ? t('Scan, or type a barcode / code / name') : t('Scanning off — type a code or name, then Add');
        }
    });

    muteBox?.addEventListener('change', scheduleDraft);
    dateInput?.addEventListener('change', scheduleDraft);
    notesInput?.addEventListener('blur', scheduleDraft);
    saveBtn?.addEventListener('click', saveIntake);

    root.querySelector('[data-discard-draft]')?.addEventListener('click', async () => {
        await fetchJson(root.dataset.discardUrl, { method: 'DELETE' });
        rows.clear();
        renderRows();
        root.querySelector('[data-draft-banner]')?.remove();
        scannerApi?.focus();
    });

    /* ------------------------------------------------------------ typeahead ---- */

    /**
     * Close the suggestion list.
     */
    function hideSuggestions() {
        window.clearTimeout(searchTimer);
        searchSeq += 1;
        suggestions = [];
        activeSuggest = 0;
        if (suggestEl) {
            suggestEl.hidden = true;
            suggestEl.classList.add('is-hidden');
            suggestEl.innerHTML = '';
        }
        scanInput?.setAttribute('aria-expanded', 'false');
    }

    /**
     * One line under the name: barcode, shape, size, tray.
     *
     * @param {object} stone
     * @returns {string}
     */
    function suggestMeta(stone) {
        return [stone.barcode ? `Barcode ${stone.barcode}` : '', stone.shape, stone.dimension, stone.tray_no ? `Tray ${stone.tray_no}` : '']
            .filter((part) => part && part !== 'N/A')
            .join(' · ') || '—';
    }

    /**
     * Paint the list under the field. The first row is active, so Enter adds it.
     */
    function renderSuggestions() {
        if (!suggestEl || !scanWrap) {
            return;
        }

        const term = String(scanInput?.value || '').trim();
        if (!suggestions.length) {
            if (!term) {
                hideSuggestions();
                return;
            }
            suggestEl.innerHTML = '<li class="pos-suggest__empty">' + t('No stone matches. Press Enter to try it as a barcode.') + '</li>';
        } else {
            suggestEl.innerHTML = suggestions.map((stone, index) => {
                const lead = stone.image_url
                    ? `<span class="pos-thumb"><img src="${escapeHtml(stone.image_url)}" alt="" width="32" height="32" loading="lazy"></span>`
                    : '<span class="pos-thumb"><span class="material-symbols-outlined icon icon--sm">diamond</span></span>';

                return `<li class="pos-suggest__item pos-suggest__cols${index === activeSuggest ? ' is-active' : ''}" role="option" aria-selected="${index === activeSuggest}" data-suggest-index="${index}">
                    ${lead}
                    <span class="pos-suggest__code">${escapeHtml(stone.stone_code)}</span>
                    <span class="pos-suggest__main">
                        <span class="pos-suggest__title">${escapeHtml(stone.name)}</span>
                        <span class="pos-suggest__meta">${escapeHtml(suggestMeta(stone))}</span>
                    </span>
                    <span class="pos-chip pos-chip--existing">${isGramLot(stone) ? `${gramsText(Number(stone.weight_grams || 0))} g` : `${Number(stone.quantity || 0)} in stock`}</span>
                </li>`;
            }).join('');
        }

        const rect = scanWrap.getBoundingClientRect();
        suggestEl.style.left = `${rect.left}px`;
        suggestEl.style.width = `${rect.width}px`;
        suggestEl.style.top = `${rect.bottom + 4}px`;
        suggestEl.hidden = false;
        suggestEl.classList.remove('is-hidden');
        scanInput?.setAttribute('aria-expanded', 'true');
    }

    /**
     * Search registered stones by name, STN code, barcode or tray.
     *
     * @param {string} term
     */
    function searchStones(term) {
        window.clearTimeout(searchTimer);
        const needle = term.trim();
        if (needle.length < 1) {
            hideSuggestions();
            return;
        }

        const seq = ++searchSeq;
        searchTimer = window.setTimeout(async () => {
            let json = {};
            try {
                ({ json } = await fetchJson(`${root.dataset.searchUrl}?q=${encodeURIComponent(needle)}`, { method: 'GET' }));
            } catch (error) {
                return;
            }
            // A newer keystroke, a scan that cleared the field, or a scanner
            // burst in progress all win over this answer.
            if (seq !== searchSeq || Date.now() < burstUntil || String(scanInput?.value || '').trim() === '') {
                return;
            }
            suggestions = json.data?.stones || [];
            activeSuggest = 0;
            renderSuggestions();
        }, 150);
    }

    /**
     * Add the highlighted (or clicked) stone, then clear the field.
     *
     * @param {number} index
     */
    function pickSuggestion(index) {
        const stone = suggestions[index];
        if (!stone) {
            return;
        }
        hideSuggestions();
        if (scanInput) {
            scanInput.value = '';
        }
        addStone(stone, false);
    }

    // A scanner types its whole code in a few milliseconds. Keys that close
    // together are not a person searching, so the list stays shut and the
    // closing Enter is handled as a scan (+1) instead of picking a row.
    let lastKeyAt = 0;
    let burstUntil = 0;
    document.addEventListener('keydown', (event) => {
        if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        const now = Date.now();
        if (lastKeyAt && now - lastKeyAt <= Number(scannerConfig.inter_keystroke_ms || 50)) {
            burstUntil = now + 600;
            hideSuggestions();
        }
        lastKeyAt = now;
    }, true);

    scanInput?.addEventListener('input', () => {
        if (Date.now() >= burstUntil) {
            searchStones(scanInput.value);
        }
    });

    scanInput?.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && suggestions.length) {
            event.preventDefault();
            activeSuggest = (activeSuggest + 1) % suggestions.length;
            renderSuggestions();
        } else if (event.key === 'ArrowUp' && suggestions.length) {
            event.preventDefault();
            activeSuggest = (activeSuggest - 1 + suggestions.length) % suggestions.length;
            renderSuggestions();
        } else if (event.key === 'Escape') {
            hideSuggestions();
        } else if (event.key === 'Enter' && suggestions.length) {
            // The scanner helper steps aside while a list is open (shouldSubmitScan).
            event.preventDefault();
            pickSuggestion(activeSuggest);
        }
    });

    // mousedown, not click: the field must not lose focus before the pick lands.
    suggestEl?.addEventListener('mousedown', (event) => {
        const item = event.target.closest('[data-suggest-index]');
        if (!item) {
            return;
        }
        event.preventDefault();
        pickSuggestion(Number(item.dataset.suggestIndex));
    });

    document.addEventListener('click', (event) => {
        if (!scanWrap?.contains(event.target)) {
            hideSuggestions();
        }
    });
    window.addEventListener('resize', hideSuggestions);

    root.querySelectorAll('[data-open-register]').forEach((button) => {
        button.addEventListener('click', () => {
            setModalOpen(registerModal, true);
            if (lastUnknown) {
                registerForm?.querySelector('[name="name"]')?.setAttribute('placeholder', lastUnknown);
            }
        });
    });
    registerModal?.querySelectorAll('[data-modal-close], [data-modal-overlay]').forEach((el) => {
        el.addEventListener('click', () => {
            setModalOpen(registerModal, false);
            scannerApi?.focus();
        });
    });

    registerForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (window.AnnigemsForm && !window.AnnigemsForm.validateForm(registerForm)) {
            return;
        }
        const body = new FormData(registerForm);
        const { ok, json } = await fetchJson(root.dataset.storeStoneUrl, { method: 'POST', body });
        if (!ok) {
            window.AnnigemsForm?.applyServerErrors(registerForm, json.errors || {});
            window.AnnigemsAlert?.notifyError(json.message || t('Could not register the stone.'));
            return;
        }
        // Registering already opens the lot's stock with the pieces or grams
        // on the form, so it is not added to this batch a second time.
        const stone = json.data?.stone;
        if (stone) {
            const opening = isGramLot(stone)
                ? `${gramsText(Number(stone.weight_grams || 0))} g`
                : `${Number(stone.quantity || 0)} piece(s)`;
            window.AnnigemsAlert?.notifySuccess(t(':code registered with :opening in stock. It is already counted, so do not add it to this intake again.', { code: stone.stone_code, opening: opening }));
        }
        setModalOpen(registerModal, false);
        registerForm.reset();
        scannerApi?.focus();
    });

    try {
        restoreDraft(JSON.parse(root.dataset.draft || 'null'));
    } catch (error) {
        // Ignore malformed draft JSON.
    }
}
