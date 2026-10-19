/**
 * Quotation workspace: scan / short code / name search, ticket, live recalc, server draft.
 */

import { attachBarcodeScanner } from './barcode-scanner';
import { recalculate } from './pricing-calculator';
import { destroySelects, enhanceSelects } from './select-search';
import { t } from './i18n';

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
 * Bind quotation create/edit workspace.
 */
export function bindQuotationForm() {
    const root = document.getElementById('quotation-form-root');
    if (!root) return;

    const workspaceForm = root.querySelector('[data-save-form]');

    // Registered before anything that can throw. The workspace form has no
    // action, so a scanner's Enter reaching a native submit would reload the
    // page and silently discard the quotation.
    workspaceForm?.addEventListener('submit', (event) => event.preventDefault());

    /**
     * Read a JSON data-attribute without letting bad data kill the module.
     *
     * @param {string} key dataset key
     * @param {object} fallback
     */
    function readJsonDataset(key, fallback) {
        try {
            return JSON.parse(root.dataset[key] || 'null') ?? fallback;
        } catch (error) {
            console.error(`quotation-form: could not parse data-${key}`, error);

            return fallback;
        }
    }

    const config = readJsonDataset('config', {});
    // USB-wedge timings come from scanner_config() via the view.
    const scannerConfig = readJsonDataset('scanner', {});
    const currencySymbol = config.currencySymbol || '';
    const currencyDecimals = Number(config.currencyDecimals ?? 2);

    /**
     * Format a workspace total with the live currency symbol and decimals.
     *
     * @param {number|string} value
     */
    function formatMoney(value) {
        const amount = Number(value || 0).toFixed(Number.isFinite(currencyDecimals) ? currencyDecimals : 2);

        return `${currencySymbol} ${amount}`.trim();
    }

    const routes = config.routes || {};
    let state = {
        customer_id: config.payload?.customer_id || null,
        customer_uuid: config.payload?.customer_uuid || null,
        walkin_name: config.payload?.walkin_name || '',
        walkin_phone: config.payload?.walkin_phone || '',
        quotation_date: config.payload?.quotation_date || new Date().toISOString().slice(0, 10),
        default_gold_rate: config.payload?.default_gold_rate || '',
        default_stone_price: config.payload?.default_stone_price || '',
        default_making_charge_type: config.payload?.default_making_charge_type || 'fixed',
        default_making_charge_value: config.payload?.default_making_charge_value || '',
        discount_type: config.payload?.discount_type || 'none',
        discount_value: config.payload?.discount_value || '0',
        tax_type: config.taxEnabled ? (config.taxType || 'none') : 'none',
        tax_rate: config.taxEnabled ? String(config.taxRate ?? 0) : '0',
        notes: config.payload?.notes || '',
        lines: config.payload?.lines || [],
    };

    const els = {
        lines: root.querySelector('[data-lines]'),
        empty: root.querySelector('[data-lines-empty]'),
        itemCount: root.querySelector('[data-item-count]'),
        scanInput: root.querySelector('[data-scan-input]'),
        scanError: root.querySelector('[data-scan-error]'),
        shortCodeInput: root.querySelector('[data-short-code-input]'),
        itemSearch: root.querySelector('[data-item-search]'),
        goldValue: root.querySelector('[data-gold-value]'),
        goldWeight: root.querySelector('[data-gold-weight]'),
        stoneValue: root.querySelector('[data-stone-value]'),
        makingValue: root.querySelector('[data-making-value]'),
        subtotal: root.querySelector('[data-subtotal]'),
        discountAmount: root.querySelector('[data-discount-amount]'),
        taxAmount: root.querySelector('[data-tax-amount]'),
        recargoAmount: root.querySelector('[data-recargo-amount]'),
        grandTotal: root.querySelector('[data-grand-total]'),
        purityNote: root.querySelector('[data-purity-note]'),
        customerSelect: root.querySelector('[data-customer-select]'),
        customerSelectWrap: root.querySelector('[data-customer-select-wrap]'),
        walkinToggle: root.querySelector('[data-walkin-toggle]'),
        walkinFields: root.querySelector('[data-walkin-fields]'),
        walkinName: root.querySelector('[data-doc="walkin_name"]'),
        walkinPhone: root.querySelector('[data-doc="walkin_phone"]'),
    };

    let draftTimer = null;

    /**
     * Keep staff discounts inside the SuperAdmin ceiling before maths runs.
     */
    function clampDiscount() {
        const max = config.maxDiscountPercentage;
        if (max === null || max === undefined || !config.canDiscount) {
            return;
        }

        if (state.discount_type === 'percentage' && Number(state.discount_value) > Number(max)) {
            state.discount_value = String(max);
            const el = root.querySelector('[data-doc="discount_value"]');
            if (el) el.value = state.discount_value;
        }
    }

    function render() {
        // The chosen IVA / recargo come from SuperAdmin's lists. The percentage
        // here is a preview; the server looks the rate up again from the uuid.
        const iva = config.taxEnabled ? (config.ivaRates || []).find((r) => r.uuid === state.tax_rate_uuid) : null;
        const recargo = config.taxEnabled ? (config.recargoRates || []).find((r) => r.uuid === state.recargo_rate_uuid) : null;
        state.tax_type = iva ? 'percentage' : 'none';
        state.tax_rate = iva ? String(iva.rate) : '0';
        clampDiscount();

        const result = recalculate(state.lines, {
            discount_type: state.discount_type,
            discount_value: state.discount_value,
            tax_type: state.tax_type,
            tax_rate: state.tax_rate,
            recargo_rate: recargo ? String(recargo.rate) : '0',
        });
        state.lines = result.lines;

        if (els.subtotal) els.subtotal.textContent = formatMoney(result.document.subtotal);
        if (els.discountAmount) els.discountAmount.textContent = formatMoney(result.document.discount_amount);
        if (els.taxAmount) els.taxAmount.textContent = formatMoney(result.document.tax_amount);
        if (els.recargoAmount) els.recargoAmount.textContent = formatMoney(result.document.recargo_amount);
        if (els.grandTotal) els.grandTotal.textContent = formatMoney(result.document.grand_total);

        renderBreakdown(state.lines);
        renderItemCount();
        renderLines();
        renderPurityNote();
        scheduleDraft();
    }

    /**
     * POS summary: gold / stone / making split from the live line maths.
     *
     * @param {Array<object>} lines
     */
    function renderBreakdown(lines) {
        let gold = 0;
        let stone = 0;
        let making = 0;
        let weight = 0;

        lines.forEach((line) => {
            gold += Number(line.gold_value) || 0;
            stone += Number(line.stone_value) || 0;
            making += Number(line.making_charges) || 0;

            if (line.line_type === 'jewellery_item') {
                weight += Number(line.net_weight_snapshot) || 0;
            }
        });

        if (els.goldValue) els.goldValue.textContent = formatMoney(gold);
        if (els.stoneValue) els.stoneValue.textContent = formatMoney(stone);
        if (els.makingValue) els.makingValue.textContent = formatMoney(making);
        if (els.goldWeight) {
            els.goldWeight.textContent = weight > 0 ? `(${weight.toFixed(2)}g)` : '';
        }
    }

    /**
     * Toolbar counter so the operator can see ticket size without scrolling.
     */
    function renderItemCount() {
        if (!els.itemCount) return;

        const count = state.lines.length;
        els.itemCount.textContent = count === 0
            ? t('No items scanned')
            : (count === 1 ? '1 item' : `${count} items`);
    }

    function renderPurityNote() {
        if (!els.purityNote) return;
        const purities = [...new Set(state.lines.filter((l) => l.line_type === 'jewellery_item').map((l) => l.purity_snapshot).filter(Boolean))];
        els.purityNote.classList.toggle('hidden', purities.length <= 1);
        if (purities.length > 1) {
            els.purityNote.textContent = t('This quotation has :purities different purities (:purities2). Adjust gold rates per line as needed.', { purities: purities.length, purities2: purities.join(', ') });
        }
    }

    /**
     * Escape interpolated snapshot text so item names cannot inject markup.
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
     * Zero is left blank so an unpriced line does not show a dummy 0.00.
     * Inputs use this as the value and keep 0.00 in the placeholder.
     *
     * @param {number|string} value
     * @returns {string}
     */
    function amountOrEmpty(value) {
        const amount = Number(value);

        if (!Number.isFinite(amount) || amount === 0) {
            return '';
        }

        return String(value);
    }

    /**
     * Small stacked label + control, matching .field-inline in _sales.scss.
     *
     * @param {string} label
     * @param {string} control
     * @param {string} [extraClass]
     */
    function field(label, control, extraClass = '') {
        const classes = extraClass ? `field-inline ${extraClass}` : 'field-inline';

        return `<div class="${classes}">
            <span class="field-inline__label">${label}</span>
            ${control}
        </div>`;
    }

    /**
     * Weight / metal / purity chips under the item name.
     *
     * @param {object} line
     * @returns {string}
     */
    function lineMeta(line) {
        const bits = [];

        if (line.line_type === 'jewellery_item') {
            const weight = Number(line.net_weight_snapshot);

            if (Number.isFinite(weight) && weight > 0) {
                bits.push(`Wt: ${weight.toFixed(2)}g`);
            }

            if (line.metal_snapshot) bits.push(escapeHtml(line.metal_snapshot));
            if (line.purity_snapshot) bits.push(escapeHtml(line.purity_snapshot));
            if (line.category_snapshot) bits.push(escapeHtml(line.category_snapshot));
        } else {
            bits.push(t('Loose stone'));
            if (line.category_snapshot) bits.push(escapeHtml(line.category_snapshot));
        }

        return bits.join(' · ');
    }

    function renderLines() {
        if (!els.lines) return;

        // Select2 leaves dropdown nodes on <body>; destroy before wiping lines.
        destroySelects(els.lines);

        if (!state.lines.length) {
            els.lines.innerHTML = '';
            els.empty?.classList.remove('hidden', 'is-hidden');
            return;
        }

        els.empty?.classList.add('hidden');

        els.lines.innerHTML = state.lines.map((line, index) => {
            const isJewellery = line.line_type === 'jewellery_item';
            const thumbIcon = isJewellery ? 'diamond' : 'package_2';

            const stonesHtml = isJewellery && line.stones?.length
                ? `<div class="sale-line__stones">
                    ${line.stones.map((stone, stoneIndex) => `
                        <div class="sale-line__stone">
                            <span class="sale-line__stone-name">${t(':stone_name_snapshot (:carat_snapshotct × :quantity)', { stone_name_snapshot: escapeHtml(stone.stone_name_snapshot), carat_snapshot: escapeHtml(stone.carat_snapshot), quantity: escapeHtml(stone.quantity) })}</span>
                            <input type="number" step="0.01" min="0" class="input-field" data-stone-price data-line="${index}" data-stone="${stoneIndex}" value="${escapeHtml(amountOrEmpty(stone.price_per_piece))}" placeholder="0.00" aria-label="${t('Price per piece')}" />
                            <span class="sale-line__stone-total">${escapeHtml(formatMoney(stone.total_price || 0))}</span>
                        </div>`).join('')}
                   </div>`
                : '';

            const jewelleryFields = `
                ${field('Gold rate / g', `<input type="number" step="0.01" min="0" class="input-field" data-field="gold_rate_applied" data-line="${index}" value="${escapeHtml(amountOrEmpty(line.gold_rate_applied))}" placeholder="0.00" />`)}
                ${field('Making type', `<select class="input-field" data-field="making_charge_type" data-line="${index}">
                    <option value="none" ${line.making_charge_type === 'none' ? 'selected' : ''}>${t('None')}</option>
                    <option value="fixed" ${line.making_charge_type === 'fixed' ? 'selected' : ''}>${t('Fixed')}</option>
                    <option value="per_gram" ${line.making_charge_type === 'per_gram' ? 'selected' : ''}>${t('Per gram')}</option>
                </select>`)}
                ${field('Making value', `<input type="number" step="0.01" min="0" class="input-field" data-field="making_charge_value" data-line="${index}" value="${escapeHtml(amountOrEmpty(line.making_charge_value))}" placeholder="0.00" />`)}`;

            const looseFields = `
                ${field('Qty', `<input type="number" min="1" step="1" class="input-field" data-field="quantity" data-line="${index}" value="${escapeHtml(line.quantity)}" />`)}
                ${field('Price / pc', `<input type="number" step="0.01" min="0" class="input-field" data-field="price_per_piece" data-line="${index}" value="${escapeHtml(amountOrEmpty(line.price_per_piece))}" placeholder="0.00" />`)}`;

            return `<div class="sale-line" data-line-index="${index}">
                <div class="sale-line__thumb" aria-hidden="true">
                    <span class="material-symbols-outlined icon icon--md">${thumbIcon}</span>
                </div>
                <div class="sale-line__body">
                    <div class="sale-line__header">
                        <div class="sale-line__identity">
                            <p class="sale-line__name">${escapeHtml(line.name_snapshot)}</p>
                            <p class="sale-line__code">${escapeHtml(line.item_code_snapshot)}</p>
                            <p class="sale-line__meta">${lineMeta(line)}</p>
                        </div>
                        <div class="sale-line__end">
                            <span class="sale-line__amount">${escapeHtml(formatMoney(line.line_total || 0))}</span>
                            <button type="button" class="sale-line__remove" data-remove-line="${index}" aria-label="${t('Remove line')}">
                                <span class="material-symbols-outlined icon icon--sm">delete</span>
                            </button>
                        </div>
                    </div>
                    <div class="sale-line__grid ${isJewellery ? '' : 'sale-line__grid--loose'}">
                        ${isJewellery ? jewelleryFields : looseFields}
                    </div>
                    ${stonesHtml}
                </div>
            </div>`;
        }).join('');

        enhanceSelects(els.lines);
        bindLineEvents();
    }

    function bindLineEvents() {
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
                render();
            });
        });

        els.lines.querySelectorAll('[data-stone-price]').forEach((input) => {
            input.addEventListener('change', () => {
                const line = state.lines[parseInt(input.dataset.line, 10)];
                line.stones[parseInt(input.dataset.stone, 10)].price_per_piece = input.value;
                render();
            });
        });
    }

    /**
     * POST a scan, short code, or unique name to quotations.lookup.
     *
     * @param {string} code
     */
    async function lookupCode(code) {
        const { ok, json } = await fetchJson(routes.lookup, {
            method: 'POST',
            body: JSON.stringify({ code }),
        });
        if (!ok) {
            showScanError(json.message || t('Not found'));
            return;
        }
        clearScanError();
        addLine(json.data.line);
    }

    /**
     * Inline scan feedback uses .form-error, which hides itself via .is-hidden.
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

    function addLine(line) {
        if (line.line_type === 'jewellery_item') {
            const exists = state.lines.some((l) => l.jewellery_item_id === line.jewellery_item_id);
            if (exists) {
                showScanError(t(':code is already on this quotation.', { code: line.item_code_snapshot }));
                return;
            }
            const rate = config.rateMap?.[line.purity_id_snapshot];
            if (rate) line.gold_rate_applied = rate;
            line.making_charge_type = line.making_charge_type || 'none';
            line.making_charge_value = line.making_charge_value || '0';
            state.lines.push(line);
        } else {
            const idx = state.lines.findIndex((l) => l.stone_id === line.stone_id);
            if (idx >= 0) {
                state.lines[idx].quantity = (parseInt(state.lines[idx].quantity, 10) || 1) + 1;
            } else {
                state.lines.push(line);
            }
        }
        render();
    }

    function scheduleDraft() {
        clearTimeout(draftTimer);
        draftTimer = setTimeout(async () => {
            await fetchJson(routes.draftSave, {
                method: 'POST',
                body: JSON.stringify({ payload: { ...state, quotation_id: config.quotationId || null } }),
            });
        }, config.draftDebounceMs || 800);
    }

    /**
     * Payload for store/update. Only identity + operator pricing — snapshots
     * are rebuilt server-side. stone_id is omitted on jewellery lines so a
     * leftover uuid/null cannot fail integer validation.
     *
     * @returns {object}
     */
    function buildFormPayload() {
        const walkin = Boolean(els.walkinToggle?.checked);

        return {
            ...state,
            customer_uuid: walkin ? null : (state.customer_uuid || null),
            customer_id: walkin ? null : (state.customer_id || null),
            walkin_name: walkin ? (state.walkin_name || '') : '',
            walkin_phone: walkin ? (state.walkin_phone || '') : '',
            lines: state.lines.map((line) => serializeLine(line)),
        };
    }

    /**
     * Strip display-only snapshot fields from one workspace line.
     *
     * @param {object} line
     * @returns {object}
     */
    function serializeLine(line) {
        if (line.line_type === 'loose_stone') {
            return {
                line_type: line.line_type,
                stone_id: Number.isFinite(Number(line.stone_id)) ? Number(line.stone_id) : null,
                stone_uuid: line.stone_uuid || null,
                quantity: line.quantity,
                price_per_piece: line.price_per_piece,
            };
        }

        return {
            line_type: line.line_type,
            jewellery_item_id: Number.isFinite(Number(line.jewellery_item_id)) ? Number(line.jewellery_item_id) : null,
            jewellery_item_uuid: line.jewellery_item_uuid || null,
            quantity: line.quantity,
            gold_rate_applied: line.gold_rate_applied,
            making_charge_type: line.making_charge_type,
            making_charge_value: line.making_charge_value,
            stones: (line.stones || []).map((stone) => ({
                jewellery_item_stone_id: stone.jewellery_item_stone_id,
                price_per_piece: stone.price_per_piece,
            })),
        };
    }

    /**
     * Blank the walk-in inputs and their state so a leftover name/phone is
     * never submitted alongside a selected customer.
     */
    function clearWalkinFields() {
        state.walkin_name = '';
        state.walkin_phone = '';

        if (els.walkinName) els.walkinName.value = '';
        if (els.walkinPhone) els.walkinPhone.value = '';
    }

    /**
     * Clear the Select2 customer pick without re-entering the walk-in toggle.
     */
    function clearCustomerSelect() {
        state.customer_id = null;
        state.customer_uuid = null;
        if (!els.customerSelect) {
            return;
        }
        els.customerSelect.value = '';
        window.jQuery?.(els.customerSelect).val(null).trigger('change.select2');
    }

    /**
     * Walk-in name/phone and the existing-customer Select2 are mutually exclusive.
     *
     * @param {boolean} walkin
     */
    function setWalkinMode(walkin) {
        if (els.walkinToggle) {
            els.walkinToggle.checked = walkin;
        }

        root.querySelector('.customer-picker')?.classList.toggle('is-walkin', walkin);

        if (els.customerSelect) {
            els.customerSelect.required = !walkin;
            els.customerSelectWrap?.classList.remove('hidden');
            if (walkin) {
                window.jQuery?.(els.customerSelect).select2('close');
            }
        }
        if (els.walkinName) {
            els.walkinName.required = walkin;
        }
        if (els.walkinPhone) {
            els.walkinPhone.required = walkin;
        }

        if (walkin) {
            clearCustomerSelect();
        } else {
            clearWalkinFields();
        }
    }

    /**
     * Inline required/pattern checks for the visible customer path.
     *
     * @returns {boolean}
     */
    function validateCustomer() {
        if (els.walkinToggle?.checked) {
            const nameOk = !els.walkinName || (window.AnnigemsForm?.validateField?.(els.walkinName) ?? Boolean(els.walkinName.value.trim()));
            const phoneOk = !els.walkinPhone || (window.AnnigemsForm?.validateField?.(els.walkinPhone) ?? Boolean(els.walkinPhone.value.trim()));

            return nameOk && phoneOk;
        }

        if (!els.customerSelect) {
            return Boolean(state.customer_uuid);
        }

        return window.AnnigemsForm?.validateField?.(els.customerSelect) ?? Boolean(els.customerSelect.value);
    }

    els.customerSelect?.addEventListener('change', () => {
        const uuid = els.customerSelect.value || null;
        state.customer_uuid = uuid;
        state.customer_id = null;
        if (uuid) {
            clearWalkinFields();
            if (els.walkinToggle?.checked) {
                setWalkinMode(false);
            }
        }
    });

    els.walkinToggle?.addEventListener('change', () => {
        setWalkinMode(els.walkinToggle.checked);
    });

    // Document-level fields. Discovered from the DOM rather than a hardcoded
    // list so a field added to the view can never be silently left out of the
    // payload. Both events are bound because `input` misses nothing while
    // typing and `change` covers date/select pickers; the equality guard keeps
    // the second one from re-rendering.
    root.querySelectorAll('[data-doc]').forEach((el) => {
        const key = el.dataset.doc;

        // Seed state from the rendered control so the payload always matches
        // what the operator can actually see on screen.
        state[key] = el.value;

        const sync = () => {
            if (state[key] === el.value) return;
            state[key] = el.value;
            render();
        };

        el.addEventListener('input', sync);
        el.addEventListener('change', sync);
    });

    workspaceForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!validateCustomer()) {
            return;
        }
        const btn = root.querySelector('[type="submit"]');
        const loading = window.AnnigemsButton?.startButtonLoading(btn, 'Saving...');
        const url = config.quotationUuid ? routes.update : routes.store;
        const method = config.quotationUuid ? 'PUT' : 'POST';
        const { ok, json } = await fetchJson(url, { method, body: JSON.stringify(buildFormPayload()) });
        loading?.restore();
        if (ok) {
            window.AnnigemsAlert?.notifySuccess(json.message || t('Saved'));
            if (json.data?.uuid) window.location.href = routes.show.replace('__UUID__', json.data.uuid);
        } else {
            window.AnnigemsAlert?.notifyError(describeErrors(json));
        }
    });

    /**
     * Direct invoice: workspace payload + payment method, no quotation row.
     * Modal lives outside #quotation-form-root so it is bound on document.
     */
    const invoiceForm = document.getElementById('invoice-modal-form');
    invoiceForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!validateCustomer()) {
            window.AnnigemsModal?.closeModal('invoice-modal');
            return;
        }

        if (!state.lines.length) {
            window.AnnigemsModal?.closeModal('invoice-modal');
            window.AnnigemsAlert?.notifyError(t('Add at least one item to the invoice.'));
            return;
        }

        const paymentSelect = invoiceForm.querySelector('[name="payment_method"]');
        const paymentOk = paymentSelect
            ? (window.AnnigemsForm?.validateField?.(paymentSelect) ?? Boolean(paymentSelect.value))
            : false;
        if (!paymentOk) {
            return;
        }

        const submitBtn = invoiceForm.querySelector('[type="submit"]');
        const loading = window.AnnigemsButton?.startButtonLoading(submitBtn, 'Saving...');
        const payload = {
            ...buildFormPayload(),
            payment_method: paymentSelect.value,
            payment_reference: invoiceForm.querySelector('[name="payment_reference"]')?.value || '',
        };
        const { ok, json } = await fetchJson(routes.invoice, {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        loading?.restore();

        if (ok) {
            window.AnnigemsAlert?.notifySuccess(json.message || t('Invoice created'));
            if (json.data?.uuid && routes.invoiceShow) {
                window.location.href = routes.invoiceShow.replace('__UUID__', json.data.uuid);
            }
        } else {
            window.AnnigemsAlert?.notifyError(describeErrors(json, t('The invoice could not be saved.')));
        }
    });

    /**
     * Turn a validation response into one readable sentence. Line-level errors
     * are keyed by index (lines.0), so the raw keys are useless to an operator —
     * only the messages are shown.
     *
     * @param {{message?: string, errors?: Record<string, string[]>}} json
     * @param {string} [fallback]
     * @returns {string}
     */
    function describeErrors(json, fallback) {
        const messages = Object.values(json?.errors || {})
            .flat()
            .filter(Boolean);

        if (messages.length > 0) {
            return [...new Set(messages)].join(' ');
        }

        return json?.message || fallback || t('The quotation could not be saved.');
    }

    /**
     * Rate, quantity, and notes fields must keep their own keystrokes — only
     * the scan field feeds the wedge buffer.
     *
     * @param {EventTarget|null} el
     * @returns {boolean}
     */
    function isProtectedTarget(el) {
        if (!(el instanceof HTMLElement)) return false;
        if (el === els.scanInput) return false;

        return el.matches('input, textarea, select, [contenteditable="true"]');
    }

    /**
     * Look up whatever is currently typed and reset the field. Routed through
     * the scanner helper when available so the key buffer is flushed once.
     */
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

        // Safety net for when the wedge helper is inactive: Enter in the scan
        // box must add a line, never submit the whole quotation.
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
     * Select2 name search. Endpoint: GET quotations.search?q=
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

    render();
}
