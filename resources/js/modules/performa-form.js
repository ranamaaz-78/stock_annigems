/**
 * Performa issue workspace: add goods by scan, short code, or name,
 * attach a registered creditor, no prices.
 */

import { attachBarcodeScanner } from './barcode-scanner';
import { enhanceSelects } from './select-search';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

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
 * Bind the performa create workspace.
 */
export function bindPerformaForm() {
    const root = document.getElementById('performa-form-root');
    if (!root) return;

    const workspaceForm = root.querySelector('[data-save-form]');
    workspaceForm?.addEventListener('submit', (event) => event.preventDefault());

    /**
     * @param {string} key
     * @param {object} fallback
     */
    function readJsonDataset(key, fallback) {
        try {
            return JSON.parse(root.dataset[key] || 'null') ?? fallback;
        } catch (error) {
            console.error(`performa-form: could not parse data-${key}`, error);
            return fallback;
        }
    }

    const config = readJsonDataset('config', {});
    const scannerConfig = readJsonDataset('scanner', {});
    const routes = config.routes || {};

    let state = {
        creditor_uuid: config.payload?.creditor_uuid || null,
        performa_date: config.payload?.performa_date || new Date().toISOString().slice(0, 10),
        notes: '',
        lines: [],
    };

    const els = {
        lines: root.querySelector('[data-lines]'),
        empty: root.querySelector('[data-lines-empty]'),
        itemCount: root.querySelector('[data-item-count]'),
        scanInput: root.querySelector('[data-scan-input]'),
        scanError: root.querySelector('[data-scan-error]'),
        shortCodeInput: root.querySelector('[data-short-code-input]'),
        itemSearch: root.querySelector('[data-item-search]'),
        creditorSelect: root.querySelector('[data-creditor-select]'),
    };

    /**
     * @param {string|null|undefined} value
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

    function renderItemCount() {
        if (!els.itemCount) return;
        const count = state.lines.length;
        els.itemCount.textContent = count === 0
            ? 'No items scanned'
            : (count === 1 ? '1 item' : `${count} items`);
    }

    function renderLines() {
        if (!els.lines) return;

        if (!state.lines.length) {
            els.lines.innerHTML = '';
            els.empty?.classList.remove('hidden', 'is-hidden');
            return;
        }

        els.empty?.classList.add('hidden');

        els.lines.innerHTML = state.lines.map((line, index) => {
            const isJewellery = line.line_type === 'jewellery_item';
            const qtyControl = isJewellery
                ? `<span class="sale-line__meta">1 piece</span>`
                : `<div class="field-inline">
                    <span class="field-inline__label">Qty</span>
                    <input type="number" min="1" step="1" class="input-field" data-field="quantity" data-line="${index}" value="${escapeHtml(line.quantity || 1)}" />
                   </div>`;

            return `<div class="sale-line" data-line-index="${index}">
                <div class="sale-line__thumb" aria-hidden="true">
                    <span class="material-symbols-outlined icon icon--md">${isJewellery ? 'diamond' : 'package_2'}</span>
                </div>
                <div class="sale-line__body">
                    <div class="sale-line__header">
                        <div class="sale-line__identity">
                            <p class="sale-line__name">${escapeHtml(line.name_snapshot)}</p>
                            <p class="sale-line__code">${escapeHtml(line.item_code_snapshot)}</p>
                            <p class="sale-line__meta">${isJewellery ? escapeHtml([line.metal_snapshot, line.purity_snapshot].filter(Boolean).join(' · ')) : 'Loose stone'}</p>
                        </div>
                        <div class="sale-line__end">
                            ${qtyControl}
                            <button type="button" class="sale-line__remove" data-remove-line="${index}" aria-label="Remove line">
                                <span class="material-symbols-outlined icon icon--sm">delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
        }).join('');

        els.lines.querySelectorAll('[data-remove-line]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.lines.splice(parseInt(btn.dataset.removeLine, 10), 1);
                render();
            });
        });

        els.lines.querySelectorAll('[data-field]').forEach((input) => {
            input.addEventListener('change', () => {
                const line = state.lines[parseInt(input.dataset.line, 10)];
                line[input.dataset.field] = input.value;
            });
        });
    }

    function render() {
        renderItemCount();
        renderLines();
    }

    /**
     * @param {string} message
     */
    function showScanError(message) {
        if (!els.scanError) return;
        els.scanError.textContent = message;
        els.scanError.classList.remove('is-hidden');
    }

    function clearScanError() {
        if (!els.scanError) return;
        els.scanError.textContent = '';
        els.scanError.classList.add('is-hidden');
    }

    /**
     * POST a scan, short code, or unique name to performas.lookup.
     *
     * @param {string} code
     */
    async function lookupCode(code) {
        const { ok, json } = await fetchJson(routes.lookup, {
            method: 'POST',
            body: JSON.stringify({ code }),
        });
        if (!ok) {
            showScanError(json.message || 'Not found');
            return;
        }
        clearScanError();
        addLine(json.data.line);
    }

    /**
     * @param {object} line
     */
    function addLine(line) {
        if (line.line_type === 'jewellery_item') {
            const exists = state.lines.some((row) => row.jewellery_item_id === line.jewellery_item_id);
            if (exists) {
                showScanError(`${line.item_code_snapshot} is already on this performa.`);
                return;
            }
            state.lines.push(line);
        } else {
            const idx = state.lines.findIndex((row) => row.stone_id === line.stone_id);
            if (idx >= 0) {
                state.lines[idx].quantity = (parseInt(state.lines[idx].quantity, 10) || 1) + 1;
            } else {
                state.lines.push(line);
            }
        }
        render();
    }

    function validateCreditor() {
        return window.AnnigemsForm?.validateField?.(els.creditorSelect) ?? Boolean(els.creditorSelect?.value);
    }

    function buildPayload() {
        return {
            creditor_uuid: state.creditor_uuid || null,
            performa_date: state.performa_date,
            notes: state.notes,
            lines: state.lines.map((line) => {
                if (line.line_type === 'loose_stone') {
                    return {
                        line_type: line.line_type,
                        stone_id: line.stone_id,
                        stone_uuid: line.stone_uuid || null,
                        quantity: line.quantity,
                    };
                }

                return {
                    line_type: line.line_type,
                    jewellery_item_id: line.jewellery_item_id,
                    jewellery_item_uuid: line.jewellery_item_uuid || null,
                    quantity: 1,
                };
            }),
        };
    }

    els.creditorSelect?.addEventListener('change', () => {
        state.creditor_uuid = els.creditorSelect.value || null;
    });

    root.querySelectorAll('[data-doc]').forEach((el) => {
        const key = el.dataset.doc;
        state[key] = el.value;
        const sync = () => {
            state[key] = el.value;
        };
        el.addEventListener('input', sync);
        el.addEventListener('change', sync);
    });


    workspaceForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!validateCreditor()) return;
        if (!state.lines.length) {
            window.AnnigemsAlert?.notifyError('Add at least one item to the performa.');
            return;
        }

        const btn = root.querySelector('[type="submit"]');
        const loading = window.AnnigemsButton?.startButtonLoading(btn, 'Issuing...');
        const { ok, json } = await fetchJson(routes.store, {
            method: 'POST',
            body: JSON.stringify(buildPayload()),
        });
        loading?.restore();

        if (ok) {
            window.AnnigemsAlert?.notifySuccess(json.message || 'Issued');
            if (json.data?.uuid) window.location.href = routes.show.replace('__UUID__', json.data.uuid);
        } else {
            const messages = Object.values(json?.errors || {}).flat().filter(Boolean);
            window.AnnigemsAlert?.notifyError(messages.join(' ') || json.message || 'The performa could not be saved.');
        }
    });

    /**
     * @param {EventTarget|null} el
     */
    function isProtectedTarget(el) {
        if (!(el instanceof HTMLElement)) return false;
        if (el === els.scanInput) return false;
        return el.matches('input, textarea, select, [contenteditable="true"]');
    }

    function submitScan() {
        if (!els.scanInput) return;
        if (scannerApi) {
            scannerApi.submitManual();
            return;
        }
        const code = els.scanInput.value.trim();
        if (code === '') return;
        lookupCode(code);
        els.scanInput.value = '';
    }

    let scannerApi = null;

    if (els.scanInput) {
        scannerApi = attachBarcodeScanner({
            input: els.scanInput,
            config: scannerConfig,
            onScan: (code) => lookupCode(code),
            isProtectedTarget,
            readyWrap: root.querySelector('[data-scan-wrap]'),
            enabled: true,
        });

        els.scanInput.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            submitScan();
        });

        root.querySelector('[data-scan-submit]')?.addEventListener('click', submitScan);
    }

    /**
     * 3-letter stone short code. Looks up when three letters are typed or Enter.
     * The USB wedge does not capture this input.
     */
    if (els.shortCodeInput) {
        const submitShortCode = () => {
            const code = String(els.shortCodeInput.value || '').trim().toUpperCase();
            if (code.length !== 3) {
                return;
            }
            els.shortCodeInput.value = '';
            lookupCode(code);
        };

        els.shortCodeInput.addEventListener('input', () => {
            const next = String(els.shortCodeInput.value || '').toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3);
            if (els.shortCodeInput.value !== next) {
                els.shortCodeInput.value = next;
            }
            if (next.length === 3) {
                submitShortCode();
            }
        });

        els.shortCodeInput.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') {
                return;
            }
            event.preventDefault();
            submitShortCode();
        });
    }

    /**
     * Select2 name search. Endpoint: GET performas.search?q=
     * Picking a result adds the line and clears the dropdown.
     */
    els.itemSearch?.addEventListener('change', () => {
        const uuid = els.itemSearch.value;
        if (!uuid) {
            return;
        }

        const selected = window.jQuery?.(els.itemSearch).select2('data')?.[0];
        if (selected?.line) {
            clearScanError();
            addLine(selected.line);
        }

        els.itemSearch.value = '';
        window.jQuery?.(els.itemSearch).val(null).trigger('change.select2');
    });

    enhanceSelects(root);
    render();
}
