/**
 * Jewellery item form: barcode scanner, live stone totals, duplicate-scan
 * increment, category/metal cascading selects, and delete confirmation.
 *
 * Lookup POST /branch/jewellery-items/stones/lookup → { success, data.stone }.
 * Search Select2 GET /branch/jewellery-items/stones/search?q= → { success, data.stones }.
 */

import { attachBarcodeScanner } from './barcode-scanner';
import { refreshSelect } from './select-search';

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
async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers || {}),
        },
        ...options,
    });
    const json = await response.json().catch(() => ({}));

    return { ok: response.ok, status: response.status, json };
}

/**
 * Bind create/edit jewellery form behaviour.
 */
export function bindJewelleryItemForm() {
    bindDeleteConfirm();

    const root = document.querySelector('[data-jewellery-root]');
    if (!root) {
        return;
    }

    const form = root.closest('[data-jewellery-form]') || root.closest('form');
    const gramsPerCarat = Number(root.dataset.gramsPerCarat || 0.2);
    const scanInput = root.querySelector('[data-scan-input]');
    const messageEl = root.querySelector('[data-scan-message]');
    const rowsBody = root.querySelector('[data-stone-rows]');
    const fieldsWrap = root.querySelector('[data-stone-fields]');
    const stoneSearch = root.querySelector('[data-stone-search]');
    const categorySelect = root.querySelector('[data-category]');
    const subSelect = root.querySelector('[data-sub-category]');
    const metalSelect = root.querySelector('[data-metal]');
    const puritySelect = root.querySelector('[data-purity]');
    const grossInput = root.querySelector('[data-gross-weight], [name="gross_weight"]');
    const metalInput = root.querySelector('[data-metal-weight], [name="metal_weight"]');

    /** @type {Map<string, {stone: object, quantity: number}>} */
    const rows = new Map();

    let scannerConfig = {};
    try {
        scannerConfig = JSON.parse(root.dataset.scanner || '{}');
    } catch (error) {
        scannerConfig = {};
    }

    try {
        const allocated = JSON.parse(root.dataset.allocated || '[]');
        allocated.forEach((row) => {
            if (!row.uuid) {
                return;
            }
            rows.set(row.uuid, {
                stone: {
                    uuid: row.uuid,
                    stone_code: row.stone_code,
                    name: row.name,
                    carat: row.carat,
                    quantity: row.available,
                    type: row.type,
                    shape: row.shape,
                },
                quantity: Number(row.quantity || 1),
                committed: Number(row.quantity || 0),
            });
        });
    } catch (error) {
        // Ignore malformed bootstrap JSON.
    }

    /**
     * Quantity/search/modal fields keep the keyboard; the wedge captures elsewhere.
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
        return el.matches('input, textarea, select, [contenteditable="true"]');
    }

    /**
     * Inline scan message.
     *
     * @param {string} text
     */
    function setMessage(text) {
        if (!messageEl) {
            return;
        }
        messageEl.textContent = text;
        messageEl.classList.toggle('is-hidden', !text);
    }

    /**
     * Refresh displayed stone ct/g from allocated packets.
     * Metal weight is typed — never written as gross minus stones.
     */
    function updateWeights() {
        let carat = 0;
        rows.forEach((row) => {
            carat += Number(row.stone.carat || 0) * Number(row.quantity || 0);
        });
        carat = Math.round(carat * 1000) / 1000;
        const grams = Math.round(carat * gramsPerCarat * 1000) / 1000;
        const gross = Number(grossInput?.value || 0);
        const stoneEl = root.querySelector('[data-stone-weight]');
        const warn = root.querySelector('[data-weight-warning]');
        if (stoneEl) {
            stoneEl.value = `${carat.toFixed(3)} ct / ${grams.toFixed(3)} g`;
        }
        const impossible = grams > gross + 0.0005 && gross > 0;
        warn?.classList.toggle('is-hidden', !impossible);
    }

    /**
     * Metal grams must be present and not exceed typed gross.
     *
     * @returns {boolean}
     */
    function validateMetalWeight() {
        if (!metalInput || metalInput.disabled) {
            return true;
        }

        if (window.AnnigemsForm && !window.AnnigemsForm.validateField(metalInput)) {
            return false;
        }

        const metal = Number(metalInput.value || 0);
        const gross = Number(grossInput?.value || 0);
        if (gross > 0 && metal > gross + 0.0005) {
            const container = metalInput.closest('[data-field]') || metalInput.parentElement;
            const errorEl = container?.querySelector('[data-error-for="metal_weight"]');
            metalInput.classList.add('is-invalid');
            if (errorEl) {
                errorEl.textContent = 'Metal weight cannot be greater than gross weight.';
                errorEl.classList.remove('is-hidden', 'hidden');
            }
            return false;
        }

        return true;
    }

    /**
     * Draw allocation rows and sync hidden inputs for submit.
     */
    function renderRows() {
        if (!rowsBody) {
            return;
        }
        rowsBody.innerHTML = '';
        if (fieldsWrap) {
            fieldsWrap.innerHTML = '';
        }
        let index = 0;
        rows.forEach((row) => {
            const max = Number(row.stone.quantity || 0) + Number(row.committed || 0);
            const over = Number(row.quantity) > max;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div class="font-data-mono">${escapeHtml(row.stone.stone_code || '')}</div>
                    <div class="text-body-sm">${escapeHtml(row.stone.name || '')}</div>
                </td>
                <td class="font-data-mono">${escapeHtml(String(row.stone.carat || ''))}</td>
                <td>
                    <input type="number" min="1" class="input-field" data-qty="${row.stone.uuid}" value="${row.quantity}" style="width:4.5rem">
                    ${over ? '<p class="form-error">More than available on hand.</p>' : ''}
                </td>
                <td class="font-data-mono">${max}</td>
                <td><button type="button" class="form-link text-body-sm" data-remove="${row.stone.uuid}">Remove</button></td>
            `;
            rowsBody.appendChild(tr);
            if (fieldsWrap) {
                fieldsWrap.insertAdjacentHTML('beforeend', `
                    <input type="hidden" name="stones[${index}][uuid]" value="${row.stone.uuid}">
                    <input type="hidden" name="stones[${index}][quantity]" value="${row.quantity}">
                `);
            }
            index += 1;
        });
        updateWeights();
    }

    /**
     * Add or increment a stone row.
     *
     * @param {object} stone
     * @param {number} [qty]
     */
    function addStone(stone, qty = 1) {
        const existing = rows.get(stone.uuid);
        if (existing) {
            existing.quantity += qty;
        } else {
            rows.set(stone.uuid, { stone, quantity: qty, committed: 0 });
        }
        setMessage('');
        renderRows();
    }

    /**
     * Lookup POST. Endpoint: jewellery-items.stones.lookup.
     *
     * @param {string} code
     */
    async function lookup(code) {
        const trimmed = String(code || '').trim();
        if (!trimmed) {
            return;
        }
        const body = new FormData();
        body.append('code', trimmed);
        const { ok, status, json } = await requestJson(root.dataset.lookupUrl, { method: 'POST', body });
        if (status === 404 || !ok) {
            setMessage(json.message || 'Barcode not found.');
            return;
        }
        if (json.data?.stone) {
            addStone(json.data.stone, 1);
            if (scanInput) {
                scanInput.value = '';
            }
        }
    }

    if (scanInput) {
        attachBarcodeScanner({
            input: scanInput,
            config: scannerConfig,
            onScan: (code) => lookup(code),
            isProtectedTarget,
            readyWrap: root.querySelector('[data-scan-wrap]'),
            enabled: true,
        });
    }

    root.querySelector('[data-lookup-button]')?.addEventListener('click', () => {
        lookup(scanInput?.value || '');
    });

    /**
     * Dedicated 3-letter field. Looks up when three letters are typed or
     * Enter is pressed. The USB wedge does not capture this input.
     */
    const shortCodeInput = root.querySelector('[data-short-code-input]');
    if (shortCodeInput) {
        const submitShortCode = () => {
            const code = String(shortCodeInput.value || '').trim().toUpperCase();
            if (code.length !== 3) {
                return;
            }
            shortCodeInput.value = '';
            lookup(code);
        };

        shortCodeInput.addEventListener('input', () => {
            const next = String(shortCodeInput.value || '').toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3);
            if (shortCodeInput.value !== next) {
                shortCodeInput.value = next;
            }
            if (next.length === 3) {
                submitShortCode();
            }
        });

        shortCodeInput.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') {
                return;
            }
            event.preventDefault();
            submitShortCode();
        });
    }

    rowsBody?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove]');
        if (!button) {
            return;
        }
        rows.delete(button.dataset.remove);
        renderRows();
    });

    rowsBody?.addEventListener('change', (event) => {
        const input = event.target.closest('[data-qty]');
        if (!input) {
            return;
        }
        const row = rows.get(input.dataset.qty);
        if (!row) {
            return;
        }
        row.quantity = Math.max(1, Number(input.value || 1));
        renderRows();
    });

    grossInput?.addEventListener('blur', () => {
        updateWeights();
        validateMetalWeight();
    });
    grossInput?.addEventListener('change', () => {
        updateWeights();
        validateMetalWeight();
    });
    metalInput?.addEventListener('blur', validateMetalWeight);

    /**
     * Select2 search: picking a result adds the packet and clears the
     * dropdown so the next search starts empty. Native change is bridged
     * from select2:select by select-search.js.
     *
     * Endpoint: GET jewellery-items.stones.search?q=
     */
    stoneSearch?.addEventListener('change', () => {
        const uuid = stoneSearch.value;
        if (!uuid) {
            return;
        }

        const selected = window.jQuery?.(stoneSearch).select2('data')?.[0];
        const stone = selected?.stone;
        if (stone?.uuid) {
            addStone(stone, 1);
        }

        stoneSearch.value = '';
        window.jQuery?.(stoneSearch).val(null).trigger('change.select2');
    });

    /**
     * Load sub-categories for the selected category uuid.
     */
    async function loadSubs() {
        const option = categorySelect?.selectedOptions[0];
        const uuid = option?.dataset.uuid;
        if (!subSelect) {
            return;
        }
        const selected = root.dataset.selectedSub || '';
        subSelect.innerHTML = '<option value="">None</option>';
        if (!uuid) {
            refreshSelect(subSelect);
            return;
        }
        const url = root.dataset.subsUrl.replace('__UUID__', uuid);
        const { ok, json } = await requestJson(url);
        if (!ok) {
            refreshSelect(subSelect);
            return;
        }
        (json.data?.sub_categories || []).forEach((sub) => {
            const opt = document.createElement('option');
            opt.value = sub.id;
            opt.textContent = sub.name;
            if (String(sub.id) === String(selected)) {
                opt.selected = true;
            }
            subSelect.appendChild(opt);
        });
        refreshSelect(subSelect);
    }

    /**
     * Load purities for the selected metal uuid.
     */
    async function loadPurities() {
        const option = metalSelect?.selectedOptions[0];
        const uuid = option?.dataset.uuid;
        if (!puritySelect) {
            return;
        }
        const selected = root.dataset.selectedPurity || '';
        puritySelect.innerHTML = '<option value="">Select…</option>';
        if (!uuid) {
            refreshSelect(puritySelect);
            return;
        }
        const url = root.dataset.puritiesUrl.replace('__UUID__', uuid);
        const { ok, json } = await requestJson(url);
        if (!ok) {
            refreshSelect(puritySelect);
            return;
        }
        (json.data?.purities || []).forEach((purity) => {
            const opt = document.createElement('option');
            opt.value = purity.id;
            opt.textContent = purity.name;
            if (String(purity.id) === String(selected)) {
                opt.selected = true;
            }
            puritySelect.appendChild(opt);
        });
        refreshSelect(puritySelect);
    }

    categorySelect?.addEventListener('change', () => {
        root.dataset.selectedSub = '';
        loadSubs();
    });
    metalSelect?.addEventListener('change', () => {
        root.dataset.selectedPurity = '';
        loadPurities();
    });

    form?.addEventListener('submit', (event) => {
        renderRows();
        if (!validateMetalWeight()) {
            event.preventDefault();
            event.stopPropagation();
            const controller = form._loadingController;
            if (controller) {
                controller.restore();
            }
        }
    });

    loadSubs();
    loadPurities();
    renderRows();
}

/**
 * SweetAlert before deleting a piece (states how many stones return).
 */
function bindDeleteConfirm() {
    document.querySelectorAll('[data-confirm-delete-item]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmed === '1') {
                return;
            }
            event.preventDefault();
            const count = form.dataset.stonesCount || '0';
            const ok = await window.AnnigemsAlert?.confirmAction({
                title: 'Delete this item?',
                text: `${count} allocated stone(s) will be returned to stock. The piece will be removed from inventory.`,
                confirmButtonText: 'Delete item',
            });
            if (!ok) {
                return;
            }
            form.dataset.confirmed = '1';
            form.requestSubmit();
        });
    });
}

/**
 * @param {string} value
 * @returns {string}
 */
function escapeHtml(value) {
    return String(value || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}
