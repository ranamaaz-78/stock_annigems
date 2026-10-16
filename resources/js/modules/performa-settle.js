/**
 * Performa settlement: outstanding lines with the quotation calculator.
 */

import { recalculate } from './pricing-calculator';

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
    return { ok: response.ok, json };
}

/**
 * Bind the settle workspace.
 */
export function bindPerformaSettle() {
    const root = document.getElementById('performa-settle-root');
    if (!root) return;

    let config = {};
    try {
        config = JSON.parse(root.dataset.config || '{}');
    } catch (error) {
        console.error('performa-settle: could not parse data-config', error);
        return;
    }

    const currencySymbol = config.currencySymbol || '';
    const decimals = Number(config.currencyDecimals ?? 2);
    const routes = config.routes || {};
    const rateMap = config.rateMap || {};
    const taxEnabled = Boolean(config.taxEnabled);
    // The rates the cashier picks from; blank = no IVA / no recargo.
    const ivaSelect = document.querySelector('[data-settle-iva]');
    const recargoSelect = document.querySelector('[data-settle-recargo]');
    const rateOf = (rows, uuid) => (rows || []).find((row) => row.uuid === uuid) || null;
    const chosenIva = () => (taxEnabled ? rateOf(config.ivaRates, ivaSelect?.value || '') : null);
    const chosenRecargo = () => (taxEnabled ? rateOf(config.recargoRates, recargoSelect?.value || '') : null);

    /**
     * @param {number|string} value
     */
    function formatMoney(value) {
        const amount = Number(value || 0).toFixed(Number.isFinite(decimals) ? decimals : 2);
        return `${currencySymbol} ${amount}`.trim();
    }

    /**
     * Empty placeholder instead of a literal 0.00 so the operator types a rate.
     *
     * @param {string|number|null|undefined} value
     */
    function amountOrEmpty(value) {
        if (value === '' || value === null || value === undefined) return '';
        const n = Number(value);
        if (!Number.isFinite(n) || n === 0) return '';
        return String(value);
    }

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

    /**
     * Weight / metal / purity under the item name.
     *
     * @param {object} line
     */
    function lineMeta(line) {
        const bits = [];

        if (line.line_type === 'jewellery_item') {
            const net = Number(line.net_weight_snapshot);
            const gross = Number(line.gross_weight_snapshot);
            if (Number.isFinite(net) && net > 0) bits.push(`Net ${net.toFixed(3)}g`);
            if (Number.isFinite(gross) && gross > 0) bits.push(`Gross ${gross.toFixed(3)}g`);
            if (line.metal_snapshot) bits.push(escapeHtml(line.metal_snapshot));
            if (line.purity_snapshot) bits.push(escapeHtml(line.purity_snapshot));
            if (line.category_snapshot) bits.push(escapeHtml(line.category_snapshot));
        } else {
            bits.push('Loose stone');
            bits.push(`Outstanding ${line.outstanding}${line.weighed ? ' g' : ''}`);
            if (line.category_snapshot) bits.push(escapeHtml(line.category_snapshot));
        }

        return bits.join(' · ');
    }

    /**
     * @param {string} label
     * @param {string} control
     */
    function field(label, control) {
        return `<div class="field-inline">
            <span class="field-inline__label">${label}</span>
            ${control}
        </div>`;
    }

    const state = (config.lines || []).map((line) => {
        const next = { ...line, selected: false };
        if (line.line_type === 'jewellery_item') {
            const rate = rateMap[line.purity_id_snapshot];
            next.gold_rate_applied = rate || line.gold_rate_applied || '';
            next.making_charge_type = line.making_charge_type || 'none';
            next.making_charge_value = line.making_charge_value || '';
            next.stones = (line.stones || []).map((stone) => ({ ...stone }));
        } else {
            next.payQty = '';
            next.price_per_piece = line.price_per_piece || '';
        }
        return next;
    });

    const list = root.querySelector('[data-settle-lines]');
    const els = {
        goldValue: root.querySelector('[data-gold-value]'),
        goldWeight: root.querySelector('[data-gold-weight]'),
        stoneValue: root.querySelector('[data-stone-value]'),
        makingValue: root.querySelector('[data-making-value]'),
        subtotal: root.querySelector('[data-subtotal]'),
        taxAmount: root.querySelector('[data-tax-amount]'),
        total: root.querySelector('[data-settle-total]'),
    };

    /**
     * Lines included in this payment, ready for the shared calculator.
     */
    function pricedLines() {
        return state.flatMap((line) => {
            if (line.line_type === 'jewellery_item') {
                if (!line.selected) return [];
                return [{
                    ...line,
                    quantity: 1,
                    gold_rate_applied: line.gold_rate_applied || 0,
                    making_charge_type: line.making_charge_type || 'none',
                    making_charge_value: line.making_charge_value || 0,
                    stones: line.stones || [],
                }];
            }

            const qty = payAmount(line, line.payQty);
            if (qty <= 0) return [];

            return [{
                ...line,
                // A weighed stone is one line priced per gram; grams carry the amount.
                quantity: line.weighed ? 1 : qty,
                weight_grams: line.weighed ? qty : null,
                price_per_piece: line.price_per_piece || 0,
                stones: [],
            }];
        });
    }

    function renderTotals() {
        const result = recalculate(pricedLines(), {
            discount_type: 'none',
            discount_value: 0,
            tax_type: chosenIva() ? 'percentage' : 'none',
            tax_rate: chosenIva()?.rate ?? 0,
            recargo_rate: chosenRecargo()?.rate ?? 0,
        });

        let gold = 0;
        let stone = 0;
        let making = 0;
        let weight = 0;
        result.lines.forEach((line) => {
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
        if (els.subtotal) els.subtotal.textContent = formatMoney(result.document.subtotal);
        if (els.taxAmount) els.taxAmount.textContent = formatMoney(result.document.tax_amount);
        const taxLabelEl = document.querySelector('[data-tax-label]');
        if (taxLabelEl) taxLabelEl.textContent = chosenIva()?.name || 'IVA';
        const recargoAmountEl = document.querySelector('[data-recargo-amount]');
        if (recargoAmountEl) recargoAmountEl.textContent = formatMoney(result.document.recargo_amount);
        const recargoLabelEl = document.querySelector('[data-recargo-label]');
        if (recargoLabelEl) recargoLabelEl.textContent = chosenRecargo()?.name || 'Recargo de equivalencia';
        if (els.total) els.total.textContent = formatMoney(result.document.grand_total);
        if (els.goldWeight) {
            els.goldWeight.textContent = weight > 0 ? `(${weight.toFixed(3)}g)` : '';
        }

        return result;
    }

    function remaining(line) {
        if (line.line_type === 'jewellery_item') {
            return line.selected ? 0 : line.outstanding;
        }
        const qty = payAmount(line, line.payQty);
        return Math.max(0, Math.round((line.outstanding - qty) * 1000) / 1000);
    }

    /**
     * What the operator typed to pay, in the line's unit: whole pieces, or grams.
     *
     * @param {object} line
     * @param {string|number} value
     * @returns {number}
     */
    function payAmount(line, value) {
        if (line.weighed) {
            return Math.max(0, Math.round((parseFloat(value) || 0) * 1000) / 1000);
        }

        return Math.max(0, parseInt(value, 10) || 0);
    }

    function lineTotal(line) {
        const priced = pricedLines().filter((row) => row.id === line.id);
        if (!priced.length) return '0.00';
        return recalculate(priced, {
            discount_type: 'none',
            discount_value: 0,
            tax_type: 'none',
            tax_rate: 0,
        }).lines[0]?.line_total || '0.00';
    }

    function render() {
        if (!list) return;

        list.innerHTML = state.map((line, index) => {
            const isJewellery = line.line_type === 'jewellery_item';
            const left = remaining(line);

            const jewelleryFields = `
                ${field('Include', `<label class="form-check">
                    <input type="checkbox" data-select="${index}" ${line.selected ? 'checked' : ''}>
                    Settle this piece
                </label>`)}
                ${field('Gold rate / g', `<input type="number" step="0.01" min="0" class="input-field" data-field="gold_rate_applied" data-line="${index}" value="${escapeHtml(amountOrEmpty(line.gold_rate_applied))}" placeholder="0.00" />`)}
                ${field('Making type', `<select class="input-field" data-field="making_charge_type" data-line="${index}">
                    <option value="none" ${line.making_charge_type === 'none' ? 'selected' : ''}>None</option>
                    <option value="fixed" ${line.making_charge_type === 'fixed' ? 'selected' : ''}>Fixed</option>
                    <option value="per_gram" ${line.making_charge_type === 'per_gram' ? 'selected' : ''}>Per gram</option>
                </select>`)}
                ${field('Making value', `<input type="number" step="0.01" min="0" class="input-field" data-field="making_charge_value" data-line="${index}" value="${escapeHtml(amountOrEmpty(line.making_charge_value))}" placeholder="0.00" />`)}`;

            const looseFields = `
                ${field(line.weighed ? 'Pay grams' : 'Pay qty', `<input type="number" min="0" max="${line.outstanding}" step="${line.weighed ? '0.001' : '1'}" class="input-field" data-qty="${index}" value="${escapeHtml(line.payQty)}" placeholder="${line.weighed ? 'grams' : '0'}" />`)}
                ${field(line.weighed ? 'Price / g' : 'Price / pc', `<input type="number" min="0" step="0.01" class="input-field" data-field="price_per_piece" data-line="${index}" value="${escapeHtml(amountOrEmpty(line.price_per_piece))}" placeholder="0.00" />`)}
                ${field('Remaining', `<span class="font-data-mono">${left}</span>`)}`;

            const stonesHtml = isJewellery && line.stones?.length
                ? `<div class="sale-line__stones">
                    ${line.stones.map((stone, stoneIndex) => `
                        <div class="sale-line__stone">
                            <span class="sale-line__stone-name">${escapeHtml(stone.stone_name_snapshot)} (${escapeHtml(stone.carat_snapshot)}ct × ${escapeHtml(stone.quantity)})</span>
                            <input type="number" step="0.01" min="0" class="input-field" data-stone-price data-line="${index}" data-stone="${stoneIndex}" value="${escapeHtml(amountOrEmpty(stone.price_per_piece))}" placeholder="0.00" aria-label="Price per piece" />
                        </div>`).join('')}
                   </div>`
                : '';

            return `<div class="sale-line" data-line-index="${index}">
                <div class="sale-line__thumb" aria-hidden="true">
                    <span class="material-symbols-outlined icon icon--md">${isJewellery ? 'diamond' : 'package_2'}</span>
                </div>
                <div class="sale-line__body">
                    <div class="sale-line__header">
                        <div class="sale-line__identity">
                            <p class="sale-line__name">${escapeHtml(line.name_snapshot)}</p>
                            <p class="sale-line__code">${escapeHtml(line.item_code_snapshot)}</p>
                            <p class="sale-line__meta">${lineMeta(line)}</p>
                        </div>
                        <div class="sale-line__end">
                            <span class="sale-line__amount">${escapeHtml(formatMoney(lineTotal(line)))}</span>
                        </div>
                    </div>
                    <div class="sale-line__grid ${isJewellery ? '' : 'sale-line__grid--loose'}">
                        ${isJewellery ? jewelleryFields : looseFields}
                    </div>
                    ${stonesHtml}
                </div>
            </div>`;
        }).join('');

        renderTotals();
        bindLineEvents();
    }

    [ivaSelect, recargoSelect].forEach((select) => select?.addEventListener("change", renderTotals));

    function bindLineEvents() {
        list.querySelectorAll('[data-select]').forEach((input) => {
            input.addEventListener('change', () => {
                state[parseInt(input.dataset.select, 10)].selected = input.checked;
                render();
            });
        });

        list.querySelectorAll('[data-qty]').forEach((input) => {
            input.addEventListener('change', () => {
                const line = state[parseInt(input.dataset.qty, 10)];
                const qty = payAmount(line, input.value);
                line.payQty = String(Math.min(qty, line.outstanding));
                render();
            });
        });

        list.querySelectorAll('[data-field]').forEach((input) => {
            input.addEventListener('change', () => {
                const line = state[parseInt(input.dataset.line, 10)];
                line[input.dataset.field] = input.value;
                render();
            });
        });

        list.querySelectorAll('[data-stone-price]').forEach((input) => {
            input.addEventListener('change', () => {
                const line = state[parseInt(input.dataset.line, 10)];
                line.stones[parseInt(input.dataset.stone, 10)].price_per_piece = input.value;
                render();
            });
        });
    }

    /**
     * Lines that have a qty and are included in this payment.
     */
    function selectedLines() {
        return state.flatMap((line) => {
            if (line.line_type === 'jewellery_item') {
                if (!line.selected) return [];
                return [{
                    performa_item_id: line.id,
                    quantity: 1,
                    gold_rate_applied: line.gold_rate_applied || '0',
                    making_charge_type: line.making_charge_type || 'none',
                    making_charge_value: line.making_charge_value || '0',
                    stones: (line.stones || []).map((stone) => ({
                        jewellery_item_stone_id: stone.jewellery_item_stone_id,
                        price_per_piece: stone.price_per_piece || '0',
                    })),
                }];
            }

            const qty = payAmount(line, line.payQty);
            if (qty <= 0 || line.price_per_piece === '' || Number(line.price_per_piece) < 0) {
                return [];
            }

            return [{
                performa_item_id: line.id,
                quantity: qty,
                price_per_piece: line.price_per_piece,
            }];
        });
    }

    root.querySelector('[data-settle-remaining]')?.addEventListener('click', () => {
        state.forEach((line) => {
            if (line.line_type === 'jewellery_item') {
                line.selected = true;
            } else {
                line.payQty = String(line.outstanding);
            }
        });
        render();
    });

    const payForm = document.getElementById('settle-pay-form');
    payForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const lines = selectedLines();
        if (!lines.length) {
            window.AnnigemsModal?.closeModal('settle-pay-modal');
            window.AnnigemsAlert?.notifyError('Select at least one line and enter its price.');
            return;
        }

        const paymentSelect = payForm.querySelector('[name="payment_method"]');
        const paymentOk = paymentSelect
            ? (window.AnnigemsForm?.validateField?.(paymentSelect) ?? Boolean(paymentSelect.value))
            : false;
        if (!paymentOk) return;

        const submitBtn = payForm.querySelector('[type="submit"]');
        const loading = window.AnnigemsButton?.startButtonLoading(submitBtn, 'Saving...');
        const { ok, json } = await fetchJson(routes.settle, {
            method: 'POST',
            body: JSON.stringify({
                payment_method: paymentSelect.value,
                payment_reference: payForm.querySelector('[name="payment_reference"]')?.value || '',
                // Blank = none; only sent when the tax controls exist.
                ...(taxEnabled ? { tax_rate_uuid: ivaSelect?.value || '', recargo_rate_uuid: recargoSelect?.value || '' } : {}),
                lines,
            }),
        });
        loading?.restore();

        if (ok) {
            window.AnnigemsAlert?.notifySuccess(json.message || 'Payment recorded');
            if (json.data?.uuid && routes.invoiceShow) {
                window.location.href = routes.invoiceShow.replace('__UUID__', json.data.uuid);
            }
        } else {
            const messages = Object.values(json?.errors || {}).flat().filter(Boolean);
            window.AnnigemsAlert?.notifyError(messages.join(' ') || json.message || 'The payment could not be saved.');
        }
    });

    render();
}
