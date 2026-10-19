/**
 * Fullscreen POS: one single-column live document.
 *
 * Customer is picked from a typeahead (or typed as a walk-in), scanning or
 * searching adds a line, qty / rate / making are typed on the sheet, and
 * Amount is always calculated. Discount and tax live in the totals stack.
 * Generate saves, prints in place (hidden iframe), and clears the ticket.
 *
 * Endpoints:
 *   GET  customers.search?catalog=1 — customer registry, cached on page load
 *   POST quotations.lookup         — barcode / short code / unique name
 *   GET  quotations.search?catalog=1 — addable catalog, cached on page load
 *   POST quotations.store          — generate quotation (JSON, print_url)
 *   POST quotations.invoice        — generate invoice after payment modal
 */

import { attachBarcodeScanner } from './barcode-scanner';
import { recalculate } from './pricing-calculator';
import { destroySelects } from './select-search';
import { t } from './i18n';

/** Short month names for the "d M Y" date format. */
const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * CSRF token from the layout meta tag.
 *
 * @returns {string}
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/**
 * JSON fetch with the shared AJAX envelope.
 *
 * @param {string} url
 * @param {RequestInit} [options]
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
 * Bind the fullscreen POS page. No-op on every other screen.
 */
export function bindPos() {
    const root = document.getElementById('pos-root');
    if (!root) return;

    /**
     * Read a JSON data-attribute without letting bad data kill the module.
     *
     * @param {string} key
     * @param {object} fallback
     */
    function readJsonDataset(key, fallback) {
        try {
            return JSON.parse(root.dataset[key] || 'null') ?? fallback;
        } catch (error) {
            console.error(`pos: could not parse data-${key}`, error);

            return fallback;
        }
    }

    const config = readJsonDataset('config', {});
    const scannerConfig = readJsonDataset('scanner', {});
    const currencySymbol = config.currencySymbol || '';
    const currencyDecimals = Number(config.currencyDecimals ?? 2);
    const routes = config.routes || {};
    const validityDays = Number(config.validityDays ?? 0);

    /**
     * Format a money amount with the live currency symbol.
     *
     * @param {number|string} value
     */
    function formatMoney(value) {
        const amount = Number(value || 0).toFixed(Number.isFinite(currencyDecimals) ? currencyDecimals : 2);

        return `${currencySymbol} ${amount}`.trim();
    }

    /**
     * Format without the currency prefix (sheet table cells).
     *
     * @param {number|string} value
     */
    function formatAmount(value) {
        return Number(value || 0).toFixed(Number.isFinite(currencyDecimals) ? currencyDecimals : 2);
    }

    /**
     * Render an ISO date with the configured date_format so the live sheet
     * reads the same as the printed one.
     *
     * @param {string} iso YYYY-MM-DD
     * @returns {string}
     */
    function formatDate(iso) {
        const [year, month, day] = String(iso || '').split('-');
        if (!year || !month || !day) {
            return iso || '—';
        }

        if (config.dateFormat === 'Y-m-d') {
            return `${year}-${month}-${day}`;
        }
        if (config.dateFormat === 'd M Y') {
            return `${day} ${MONTH_NAMES[Number(month) - 1] || month} ${year}`;
        }

        return `${day}-${month}-${year}`;
    }

    /**
     * Add days to an ISO date without drifting across timezones.
     *
     * @param {string} iso
     * @param {number} days
     * @returns {string}
     */
    function addDays(iso, days) {
        const date = new Date(`${iso}T00:00:00`);
        if (Number.isNaN(date.getTime())) {
            return iso;
        }
        date.setDate(date.getDate() + days);

        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
    }

    /**
     * A SuperAdmin rate by its uuid, from the lists the page was given.
     *
     * @param {Array<{uuid: string, name: string, rate: number}>|undefined} rates
     * @param {string} uuid
     */
    function rateByUuid(rates, uuid) {
        return (rates || []).find((rate) => rate.uuid === uuid) || null;
    }

    let state = emptyState();
    // Invoice when the cashier may convert, otherwise only quotations exist.
    let documentKind = ['invoice', 'delivery_note', 'performa'].includes(config.defaultKind) ? config.defaultKind : 'quotation';
    // Which registry the phone field searches: customers, or creditors on a performa.
    let identityGroup = 'customer';
    // Line whose grams box takes the cursor after the next paint (a weighed stone was just added).
    let pendingGramsFocus = null;
    // An issued performa opened for editing (null on a new ticket).
    const editing = config.editingPerforma || null;
    // An invoice or delivery note with no payment yet, opened for editing.
    const editingDoc = config.editingDocument || null;
    // Which document the payment dialog is for.
    let payTarget = 'invoice';
    // Next number of each series, as last read from the server.
    let documentNumbers = { ...(config.documentNumbers || {}) };

    /**
     * Fresh ticket: no customer, no lines, tax off, discount off.
     *
     * @returns {object}
     */
    function emptyState() {
        return {
            customer_uuid: null,
            walkin_name: '',
            walkin_phone: '',
            walkin_email: '',
            walkin_nie: '',
            walkin_address: '',
            customerLocked: false,
            customer_code: '',
            // The rates SuperAdmin keeps: the chosen IVA (default preselected) and an optional recargo.
            iva_uuid: config.defaultIvaUuid || '',
            recargo_uuid: '',
            quotation_date: config.quotationDate || new Date().toISOString().slice(0, 10),
            discount_on: false,
            discount_type: 'none',
            discount_value: '0',
            // IVA starts on with the default rate; the cashier can change the rate or
            // remove it with the x.
            apply_tax: Boolean(config.taxEnabled && config.defaultIvaUuid),
            // Paid asks for the method when generating; Pending records no payment.
            payment_status: 'paid',
            notes: '',
            lines: [],
        };
    }

    const els = {
        phone: root.querySelector('[data-pos-phone]'),
        name: root.querySelector('[data-pos-name]'),
        email: root.querySelector('[data-pos-email]'),
        nie: root.querySelector('[data-pos-nie]'),
        address: root.querySelector('[data-pos-address]'),
        chip: root.querySelector('[data-customer-chip]'),
        customerSuggest: root.querySelector('[data-customer-suggest]'),
        customerWrap: root.querySelector('[data-customer-wrap]'),
        customerClear: root.querySelector('[data-customer-clear]'),
        issueDate: root.querySelector('[data-pos-date]'),
        validUntil: root.querySelector('[data-preview-valid-until]'),
        validUntilRows: root.querySelectorAll('[data-valid-until-row]'),
        lines: root.querySelector('[data-lines]'),
        clearLines: root.querySelector('[data-clear-lines]'),
        scanInput: root.querySelector('[data-scan-input]'),
        scanWrap: root.querySelector('[data-scan-wrap]'),
        scanClear: root.querySelector('[data-scan-clear]'),
        scanError: root.querySelector('[data-scan-error]'),
        scanSuggest: root.querySelector('[data-scan-suggest]'),
        purityNote: root.querySelector('[data-purity-note]'),
        notes: root.querySelector('[data-doc-notes]'),
        discountOff: root.querySelector('[data-discount-off]'),
        toggleDiscount: root.querySelector('[data-toggle-discount]'),
        discountFields: root.querySelector('[data-discount-fields]'),
        discountType: root.querySelector('[data-discount-type]'),
        discountValue: root.querySelector('[data-discount-value]'),
        clearDiscount: root.querySelector('[data-clear-discount]'),
        toggleTax: root.querySelector('[data-toggle-tax]'),
        taxOff: root.querySelector('[data-tax-off]'),
        taxApplied: root.querySelector('[data-tax-applied]'),
        taxAppliedLabel: root.querySelector('[data-tax-applied-label]'),
        clearTax: root.querySelector('[data-clear-tax]'),
        ivaSelect: root.querySelector('[data-iva-select]'),
        recargoOff: root.querySelector('[data-recargo-off]'),
        recargoApplied: root.querySelector('[data-recargo-applied]'),
        recargoSelect: root.querySelector('[data-recargo-select]'),
        toggleRecargo: root.querySelector('[data-toggle-recargo]'),
        clearRecargo: root.querySelector('[data-clear-recargo]'),
        previewRecargo: root.querySelector('[data-preview-recargo]'),
        generateQuote: root.querySelector('[data-generate-quotation]'),
        generateInvoice: root.querySelector('[data-generate-invoice]'),
        customerLocked: root.querySelector('[data-customer-locked]'),
        previewNumber: root.querySelector('[data-preview-number]'),
        previewCount: root.querySelector('[data-preview-count]'),
        previewCountLines: root.querySelector('[data-preview-count-lines]'),
        previewItemsNote: root.querySelector('[data-preview-items-note]'),
        previewLinesTotal: root.querySelector('[data-preview-lines-total]'),
        previewSubtotal: root.querySelector('[data-preview-subtotal]'),
        previewDiscount: root.querySelector('[data-preview-discount]'),
        previewTax: root.querySelector('[data-preview-tax]'),
        previewTotalNote: root.querySelector('[data-preview-total-note]'),
        previewGrandLabel: root.querySelector('[data-preview-grand-label]'),
        kindOptions: root.querySelectorAll('[data-kind]'),
        kindOnly: root.querySelectorAll('[data-kind-only]'),
        kindChip: root.querySelector('[data-kind-chip]'),
        kindNote: root.querySelector('[data-kind-note]'),
        generateDeliveryNote: root.querySelector('[data-generate-delivery-note]'),
        generatePerforma: root.querySelector('[data-generate-performa]'),
        billtoTitle: root.querySelector('[data-billto-title]'),
        actionHelp: root.querySelector('[data-action-help]'),
        previewGrand: root.querySelector('[data-preview-grand]'),
        linesFoot: root.querySelector('[data-lines-foot]'),
        payCustomer: document.querySelector('[data-pay-customer]'),
        payTotal: document.querySelector('[data-pay-total]'),
    };

    /**
     * Keep staff discounts inside the SuperAdmin ceiling before maths runs.
     */
    function clampDiscount() {
        const max = config.maxDiscountPercentage;
        if (max === null || max === undefined || !config.canDiscount || !state.discount_on) {
            return;
        }

        if (state.discount_type === 'percentage' && Number(state.discount_value) > Number(max)) {
            state.discount_value = String(max);
            if (els.discountValue) els.discountValue.value = state.discount_value;
        }
    }

    /**
     * Recalc line amounts, then paint the live document (table + totals).
     */
    function render() {
        clampDiscount();

        // A delivery note never carries tax and a performa neither tax nor discount,
        // whatever the ticket's toggles say.
        const taxed = state.apply_tax && config.taxEnabled && !['delivery_note', 'performa'].includes(documentKind);
        const discounted = state.discount_on && documentKind !== 'performa';
        // IVA and recargo are separate: either, both or neither can be on.
        const taxAllowed = config.taxEnabled && !['delivery_note', 'performa'].includes(documentKind);
        const iva = taxAllowed && state.apply_tax ? rateByUuid(config.ivaRates, state.iva_uuid) : null;
        const recargo = taxAllowed && state.recargo_uuid ? rateByUuid(config.recargoRates, state.recargo_uuid) : null;
        // The percentage shown here is a preview; the server looks the rate up again from the uuid.
        const taxType = iva ? 'percentage' : 'none';
        const taxRate = iva ? String(iva.rate) : '0';

        const result = recalculate(state.lines, {
            discount_type: discounted ? state.discount_type : 'none',
            discount_value: discounted ? state.discount_value : '0',
            tax_type: taxType,
            tax_rate: taxRate,
            recargo_rate: recargo ? String(recargo.rate) : '0',
        });
        state.lines = result.lines;

        renderLines();
        renderPurityNote();
        renderDates();
        renderPreview(result.document);
        renderAdjustButtons();
        refreshSuggestionsIfOpen();
    }

    /**
     * + Discount / + Tax stay visible until clicked; then the button hides
     * and the live fields (or applied GST label) show with an X to remove.
     */
    function renderAdjustButtons() {
        const noDiscount = documentKind === 'performa';
        els.discountOff?.classList.toggle('is-hidden', state.discount_on || noDiscount);
        els.discountFields?.classList.toggle('is-hidden', !state.discount_on || noDiscount);

        // No tax controls on a delivery note: it is issued without tax.
        const noTax = ['delivery_note', 'performa'].includes(documentKind);
        els.taxOff?.classList.toggle('is-hidden', state.apply_tax || noTax);
        els.taxApplied?.classList.toggle('is-hidden', !state.apply_tax || noTax);

        // Recargo is its own control: it does not need IVA, and removing IVA leaves it.
        const recargoOn = !noTax && Boolean(state.recargo_uuid);
        els.recargoOff?.classList.toggle('is-hidden', noTax || recargoOn);
        els.recargoApplied?.classList.toggle('is-hidden', !recargoOn);
        if (els.ivaSelect && els.ivaSelect.value !== state.iva_uuid) els.ivaSelect.value = state.iva_uuid;
        if (els.recargoSelect && state.recargo_uuid && els.recargoSelect.value !== state.recargo_uuid) {
            els.recargoSelect.value = state.recargo_uuid;
        }
    }

    /**
     * Issue date drives the quotation validity label. Invoices have no
     * validity window, so those rows are hidden by setDocumentKind().
     */
    function renderDates() {
        if (els.issueDate && els.issueDate.value !== state.quotation_date) {
            els.issueDate.value = state.quotation_date;
        }
        if (els.validUntil) {
            els.validUntil.textContent = formatDate(addDays(state.quotation_date, validityDays));
        }
        renderKindNote();
    }

    /**
     * The number this ticket will get, for the kind chosen. It is a preview
     * of the next number in that series; the real one is issued on save.
     */
    function renderDocumentNumber() {
        if (!els.previewNumber) return;

        els.previewNumber.textContent = documentNumbers[documentKind] || t('Draft');
    }

    /**
     * Fetch the next numbers again, after a save used one up.
     */
    async function refreshDocumentNumbers() {
        if (!routes.numbers) return;

        const { ok, json } = await fetchJson(routes.numbers);
        if (ok && json.data) {
            documentNumbers = { ...documentNumbers, ...json.data };
            renderDocumentNumber();
        }
    }

    /**
     * Line under the action button. For a quotation it names the date the
     * offer lapses, so it follows the issue date the cashier picks.
     */
    function renderKindNote() {
        if (!els.kindNote) return;

        if (documentKind === 'invoice') {
            els.kindNote.textContent = t('Payment is taken next. Stock is deducted when the invoice is saved.');
        } else if (documentKind === 'performa') {
            els.kindNote.textContent = t('Issued to a creditor. No discount, no tax. Stock leaves now; the creditor pays later.');
        } else if (documentKind === 'delivery_note') {
            els.kindNote.textContent = t('No tax is added. Stock leaves now; the note can be cancelled or invoiced later.');
        } else {
            els.kindNote.textContent = validityDays === 1
                ? t('Valid until :date — :count day from the issue date.', { date: formatDate(addDays(state.quotation_date, validityDays)), count: validityDays })
                : t('Valid until :date — :count days from the issue date.', { date: formatDate(addDays(state.quotation_date, validityDays)), count: validityDays });
        }
    }

    /**
     * Mixed-purity warning so gold rates are not silently mixed.
     */
    function renderPurityNote() {
        if (!els.purityNote) return;
        const purities = [...new Set(state.lines.filter((l) => l.line_type === 'jewellery_item').map((l) => l.purity_snapshot).filter(Boolean))];
        els.purityNote.classList.toggle('hidden', purities.length <= 1);
        if (purities.length > 1) {
            els.purityNote.textContent = t('This ticket has :purities different purities (:purities2). Adjust gold rates per line as needed.', { purities: purities.length, purities2: purities.join(', ') });
        }
    }

    /**
     * Escape interpolated snapshot text so item names cannot inject markup.
     *
     * @param {unknown} value
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
     * Compact right-aligned amount field used inside invoice table cells.
     *
     * @param {object} attrs
     */
    function cellInput({ type = 'number', step = '0.01', min = '0', value, placeholder = '0.00', extra = '', aria }) {
        return `<input type="${type}" step="${step}" min="${min}" class="pos-cell-input" value="${escapeHtml(value)}" placeholder="${placeholder}" ${extra} aria-label="${escapeHtml(aria)}" />`;
    }

    /**
     * Remaining-stock cell. Jewellery pieces are unique, so they only say so;
     * loose stones show what is left once this ticket is accounted for.
     *
     * @param {object} line
     * @param {number} index
     * @returns {string}
     */
    function stockCell(line, index) {
        if (line.line_type === 'jewellery_item') {
            return '<span class="pos-stock__note">' + t('Unique piece') + '</span>';
        }

        // A weighed stone's stock is grams.
        if (line.weighed) {
            const leftGrams = remainingForLine(line);

            return `<span class="pos-line__stock${leftGrams <= 0 ? ' pos-line__stock--low' : ''}" data-stock-line="${index}">${formatGrams(leftGrams)} g</span>`;
        }

        const onHand = stoneOnHand(line.stone_id, line.available_quantity);
        const left = Math.max(0, onHand - ticketStoneQty(line.stone_id));

        return `<span class="pos-line__stock${left < 1 ? ' pos-line__stock--low' : ''}" data-stock-line="${index}">${left}</span>`;
    }

    /**
     * Quantity cell. Loose stones get a minus/value/plus stepper capped at the
     * cached on-hand figure; a jewellery piece is always exactly one.
     *
     * @param {object} line
     * @param {number} index
     * @returns {string}
     */
    function quantityCell(line, index) {
        if (line.line_type === 'jewellery_item') {
            return '<span class="pos-chip"><span class="pos-num">1</span>' + t('· Unique piece') + '</span>';
        }

        // A weighed stone is sold by the gram: ask for grams instead of pieces.
        if (line.weighed) {
            return `<span class="pos-grams">
                ${cellInput({
                    type: 'number',
                    step: '0.001',
                    min: '0.001',
                    value: line.weight_grams || '',
                    placeholder: 'grams',
                    extra: `data-field="weight_grams" data-grams-input data-line="${index}" max="${lineOnHand(line) || ''}"`,
                    aria: 'Grams',
                })}
                <span class="pos-grams__unit">g</span>
            </span>`;
        }

        const qty = parseInt(line.quantity, 10) || 1;
        const onHand = stoneOnHand(line.stone_id, line.available_quantity);
        const canAdd = onHand < 1 || qty < onHand;

        return `<span class="pos-qty-step">
            <button type="button" class="pos-qty-step__btn" data-qty-step="-1" data-line="${index}" aria-label="${t('Decrease quantity')}" ${qty <= 1 ? 'disabled' : ''}>
                <span class="material-symbols-outlined icon icon--xs">remove</span>
            </button>
            ${cellInput({
                type: 'number',
                step: '1',
                min: '1',
                value: qty,
                placeholder: '1',
                extra: `data-field="quantity" data-line="${index}"${onHand > 0 ? ` max="${onHand}"` : ''}`,
                aria: 'Quantity',
            })}
            <button type="button" class="pos-qty-step__btn" data-qty-step="1" data-line="${index}" aria-label="${t('Increase quantity')}" ${canAdd ? '' : 'disabled'}>
                <span class="material-symbols-outlined icon icon--xs">add</span>
            </button>
        </span>`;
    }

    /**
     * Paint the document line table. Qty / Rate / Making are typed in-cell;
     * Amount is always calculated (qty × rate + making).
     */
    function renderLines() {
        if (!els.lines) return;

        destroySelects(els.lines);
        els.clearLines?.classList.toggle('is-hidden', state.lines.length === 0);

        const emptyTicket = state.lines.length === 0;
        els.linesFoot?.classList.toggle('is-hidden', emptyTicket);
        els.generateQuote?.toggleAttribute('disabled', emptyTicket);
        els.generateInvoice?.toggleAttribute('disabled', emptyTicket);

        if (emptyTicket) {
            els.lines.innerHTML = `<div class="pos-lines__empty">
                <span class="pos-lines__empty-icon" aria-hidden="true"><span class="material-symbols-outlined icon icon--lg">barcode_scanner</span></span>
                <p class="pos-lines__empty-title">${t('Scan or search to add the first line.')}</p>
                <p class="pos-lines__empty-copy">${t('Jewellery adds as a single unique piece. Loose stones add by quantity.')}</p>
            </div>`;
            return;
        }

        els.lines.innerHTML = state.lines.map((line, index) => {
            const isJewellery = line.line_type === 'jewellery_item';
            const weight = Number(line.net_weight_snapshot);
            const soldGrams = parseFloat(line.weight_grams) || 0;
            const weightLabel = !isJewellery && line.weighed && soldGrams > 0
                ? `${formatGrams(soldGrams)}g`
                : isJewellery && Number.isFinite(weight) && weight > 0
                ? `${weight.toFixed(3)}g`
                : '—';

            // Rate is per unit (qty × rate = amount). Jewellery qty is always 1,
            // so the rate is the gold + set-stone total for that piece.
            const rateValue = isJewellery
                ? Math.round(((Number(line.gold_value) || 0) + (Number(line.stone_value) || 0)) * 100) / 100
                : Number(line.price_per_piece) || 0;

            // Set stones: one tidy row each, name and detail on the left and the
            // price per piece to type on the right, under a single heading.
            const stoneRows = isJewellery
                ? (line.stones || []).map((stone, stoneIndex) => {
                    const name = stone.stone_name_snapshot || stone.stone_type_snapshot || t('Stone');
                    const detail = [
                        Number(stone.carat_snapshot) > 0 ? `${Number(stone.carat_snapshot)} ct` : '',
                        `× ${stone.quantity}`,
                    ].filter(Boolean).join(' · ');

                    return `<label class="pos-stone">
                        <span class="pos-stone__name">${escapeHtml(name)}<small>${escapeHtml(detail)}</small></span>
                        ${cellInput({
                            value: amountOrEmpty(stone.price_per_piece),
                            extra: `data-stone-price data-line="${index}" data-stone="${stoneIndex}"`,
                            aria: `${name} price per piece`,
                        })}
                    </label>`;
                }).join('')
                : '';

            // Metal first: what the gold of this piece comes to (net weight x
            // the day's rate for its purity), so the Rate column can be read as
            // metal + stones rather than one unexplained number.
            const goldRate = Number(line.gold_rate_applied) || 0;
            const goldValue = Number(line.gold_value) || 0;
            const metalName = [line.purity_snapshot, line.metal_snapshot].filter(Boolean).join(' ') || t('Metal');
            const metalRow = isJewellery
                ? `<div class="pos-stone__head"><span>${t('Metal')}</span><span>${t('Value')}</span></div>
                    <div class="pos-stone">
                        <span class="pos-stone__name">${escapeHtml(metalName)}<small>${goldRate > 0
                            ? `${weight.toFixed(3)} g × ${formatAmount(goldRate)} / g`
                            : t('No gold rate for this purity today')}</small></span>
                        <span class="pos-stone__value${goldRate > 0 ? '' : ' pos-stone__value--missing'}">${goldRate > 0 ? formatAmount(goldValue) : '—'}</span>
                    </div>`
                : '';

            const stonesHtml = isJewellery
                ? `${metalRow}${stoneRows ? `<div class="pos-stone__head"><span>${t('Set stones')}</span><span>${t('Price / pc')}</span></div>${stoneRows}` : ''}`
                : '';

            const makingCell = isJewellery
                ? cellInput({
                    value: amountOrEmpty(line.making_charges),
                    extra: `data-making data-line="${index}"`,
                    aria: t('Making charges'),
                })
                : '<span class="pos-line__weight">—</span>';

            const meta = isJewellery
                ? [line.purity_snapshot, line.metal_snapshot, 'Jewellery'].filter(Boolean).join(' · ')
                : [line.category_snapshot, t('Loose stone')].filter(Boolean).join(' · ');

            return `<div class="pos-line" data-line-index="${index}">
                <span class="pos-line__n">${index + 1}</span>
                <div class="pos-line__desc">
                    <span class="pos-thumb" aria-hidden="true"><span class="material-symbols-outlined icon icon--sm">${isJewellery ? 'diamond' : 'grain'}</span></span>
                    <div>
                        <p class="pos-line__name">${escapeHtml(line.name_snapshot)}</p>
                        <p class="pos-line__meta"><span class="pos-line__code">${escapeHtml(line.item_code_snapshot)}</span><span>|</span>${escapeHtml(meta)}</p>
                        ${!isJewellery && (line.details || []).length
                            ? `<p class="pos-line__badges">${line.details.map((d) => `<span class="pos-line__badge">${escapeHtml(d)}</span>`).join('')}</p>`
                            : ''}
                        ${stonesHtml ? `<div class="pos-line__stones">${stonesHtml}</div>` : ''}
                    </div>
                </div>
                ${isJewellery ? '<span class="pos-line__stock">' + t('Unique') + '</span>' : stockCell(line, index)}
                <div class="pos-lines__center">${quantityCell(line, index)}</div>
                <span class="pos-line__weight">${weightLabel}</span>
                ${cellInput({
                    value: amountOrEmpty(rateValue),
                    extra: `data-material data-line="${index}"`,
                    placeholder: line.weighed ? 'per g' : '0.00',
                    aria: line.weighed ? t('Rate per gram') : 'Rate',
                })}
                ${makingCell}
                <span class="pos-line__amount">${escapeHtml(formatAmount(line.line_total))}</span>
                <button type="button" class="pos-icon-btn" data-remove-line="${index}" aria-label="${t('Remove line')}">
                    <span class="material-symbols-outlined icon icon--xs">close</span>
                </button>
            </div>`;
        }).join('');

        bindLineEvents();

        if (pendingGramsFocus !== null) {
            els.lines.querySelector(`[data-grams-input][data-line="${pendingGramsFocus}"]`)?.focus();
            pendingGramsFocus = null;
        }
    }

    /**
     * Qty, rate, making, set-stone price, and remove.
     * Loose-stone rate is price per piece — Amount is qty × rate.
     * Jewellery rate is the gold/stone total for that unique piece (qty is 1);
     * we reverse it into gold_rate so PricingCalculationService still owns the maths.
     */
    function bindLineEvents() {
        els.lines.querySelectorAll('[data-remove-line]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.lines.splice(parseInt(btn.dataset.removeLine, 10), 1);
                render();
            });
        });

        els.lines.querySelectorAll('[data-qty-step]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const index = parseInt(btn.dataset.line, 10);
                const line = state.lines[index];
                if (!line) return;
                const next = (parseInt(line.quantity, 10) || 1) + parseInt(btn.dataset.qtyStep, 10);
                line.quantity = clampStoneQuantity(line, next);
                render();
                // The row was re-rendered, so put the cursor back on this stepper.
                els.lines.querySelector(`[data-qty-step="${btn.dataset.qtyStep}"][data-line="${index}"]`)?.focus();
            });
        });

        els.lines.querySelectorAll('[data-field]').forEach((input) => {
            input.addEventListener('change', () => {
                const line = state.lines[parseInt(input.dataset.line, 10)];
                if (input.dataset.field === 'quantity') {
                    line.quantity = clampStoneQuantity(line, input.value);
                } else if (input.dataset.field === 'weight_grams') {
                    line.weight_grams = clampGrams(line, input.value);
                } else {
                    line[input.dataset.field] = input.value;
                }
                render();
            });
        });

        els.lines.querySelectorAll('[data-material]').forEach((input) => {
            input.addEventListener('change', () => {
                const line = state.lines[parseInt(input.dataset.line, 10)];
                const amount = Number(input.value) || 0;

                if (line.line_type === 'loose_stone') {
                    line.price_per_piece = amount;
                } else {
                    const stoneTotal = (line.stones || []).reduce(
                        (sum, stone) => sum + (Number(stone.total_price) || 0),
                        0
                    );
                    const goldPart = Math.max(0, amount - stoneTotal);
                    const weight = Number(line.net_weight_snapshot) || 0;
                    line.gold_rate_applied = weight > 0 ? goldPart / weight : goldPart;
                }

                render();
            });
        });

        els.lines.querySelectorAll('[data-making]').forEach((input) => {
            input.addEventListener('change', () => {
                const line = state.lines[parseInt(input.dataset.line, 10)];
                const value = Number(input.value) || 0;
                line.making_charge_value = value;
                line.making_charge_type = value > 0 ? 'fixed' : 'none';
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
     * Fill item count and the totals stack. Line rows are owned by
     * renderLines — this is the same document, not a second preview.
     *
     * @param {object} documentTotals
     */
    /**
     * "2 pieces · 3 stones", or "0" when the ticket is empty.
     *
     * @returns {string}
     */
    function itemsSummary() {
        let pieces = 0;
        let stones = 0;
        let grams = 0;
        state.lines.forEach((line) => {
            if (line.line_type === 'jewellery_item') {
                pieces += 1;
            } else if (line.weighed) {
                grams += parseFloat(line.weight_grams) || 0;
            } else {
                stones += parseInt(line.quantity, 10) || 0;
            }
        });
        if (!state.lines.length) {
            return '0';
        }

        return [
            pieces ? `${pieces} ${pieces === 1 ? 'piece' : 'pieces'}` : null,
            stones ? `${stones} ${stones === 1 ? 'stone' : 'stones'}` : null,
            grams > 0 ? t(':grams g of stone', { grams: formatGrams(grams) }) : null,
        ].filter(Boolean).join(' · ') || `${state.lines.length} ${state.lines.length === 1 ? 'line' : 'lines'}`;
    }

    function renderPreview(documentTotals) {
        const summary = itemsSummary();
        const lineCount = state.lines.length;
        const money = formatMoney(documentTotals.grand_total);

        if (els.previewCount) els.previewCount.textContent = summary;
        if (els.previewCountLines) {
            els.previewCountLines.textContent = `${lineCount} ${lineCount === 1 ? 'line' : 'lines'}`;
        }
        if (els.previewItemsNote) els.previewItemsNote.textContent = t(':summary on this ticket', { summary: summary });
        renderDocumentNumber();

        if (els.previewSubtotal) els.previewSubtotal.textContent = formatMoney(documentTotals.subtotal);
        if (els.previewLinesTotal) els.previewLinesTotal.textContent = formatMoney(documentTotals.subtotal);

        const discountAmount = Number(documentTotals.discount_amount || 0);
        if (els.previewDiscount) {
            els.previewDiscount.textContent = discountAmount > 0 ? `− ${formatMoney(discountAmount)}` : formatMoney(0);
        }

        const taxAmount = Number(documentTotals.tax_amount || 0);
        if (els.previewTax) {
            els.previewTax.textContent = taxAmount > 0 ? `+ ${formatMoney(taxAmount)}` : formatMoney(0);
        }

        const recargoAmount = Number(documentTotals.recargo_amount || 0);
        if (els.previewRecargo) {
            els.previewRecargo.textContent = recargoAmount > 0 ? `+ ${formatMoney(recargoAmount)}` : formatMoney(0);
        }

        if (els.previewTotalNote) {
            const notes = [
                discountAmount > 0 ? t('After :discountAmount discount', { discountAmount: formatMoney(discountAmount) }) : null,
                taxAmount > 0 ? `incl. ${config.taxLabel || 'tax'}` : null,
            ].filter(Boolean);
            els.previewTotalNote.textContent = notes.join(', ') || t('No discount or tax applied');
        }

        if (els.previewGrand) els.previewGrand.textContent = money;
        if (els.payTotal) els.payTotal.textContent = money;
        if (els.payCustomer) els.payCustomer.textContent = (els.name?.value || '').trim() || t('Customer');
    }

    /**
     * Switch the live totals between a quotation and an invoice.
     * Invoices carry no validity window, so those meta rows are hidden.
     *
     * @param {'quotation'|'invoice'} kind
     */
    function setDocumentKind(kind) {
        documentKind = kind;
        const isInvoice = kind === 'invoice';
        const isQuotation = kind === 'quotation';
        const labels = { invoice: t('Grand Total'), quotation: t('Quotation Total'), delivery_note: t('Delivery Note Total'), performa: t('Performa Value') };
        if (els.previewGrandLabel) els.previewGrandLabel.textContent = labels[kind] || labels.quotation;
        els.validUntilRows?.forEach((row) => row.classList.toggle('is-hidden', !isQuotation));

        // The selector, the badge, the action button and the help all follow.
        els.kindOptions?.forEach((option) => {
            const on = option.dataset.kind === kind;
            option.classList.toggle('is-on', on);
            option.setAttribute('aria-checked', on ? 'true' : 'false');
        });
        els.kindOnly?.forEach((control) => control.classList.toggle('is-hidden', !control.dataset.kindOnly.split(' ').includes(kind)));
        if (els.kindChip) els.kindChip.textContent = { invoice: t('Invoice'), quotation: t('Quotation'), delivery_note: t('Delivery Note'), performa: t('Performa') }[kind] || t('Quotation');
        if (els.actionHelp) {
            els.actionHelp.textContent = {
                invoice: t('An invoice takes payment and marks stock sold. It prints, then clears the ticket.'),
                quotation: t('A quotation holds stock until it is converted or cancelled. It prints, then clears the ticket.'),
                delivery_note: t('A delivery note takes the stock out now and prints the client\'s copy. No tax, no payment.'),
                performa: t('A performa takes the stock out now against a creditor. No discount, no tax; payment comes later on an invoice.'),
            }[kind] || '';
        }

        // Who the ticket is for: a creditor on a performa, a customer otherwise.
        if (els.billtoTitle) els.billtoTitle.textContent = kind === 'performa' ? t('Issued to (creditor)') : t('Billed to');
        const group = kind === 'performa' ? 'creditor' : 'customer';
        if (group !== identityGroup) {
            identityGroup = group;
            // The picked person belongs to the other registry: start the fields afresh.
            unlockCustomer('idle');
            if (group === 'creditor') loadCreditors();
        }
        renderKindNote();
        renderDocumentNumber();
        // Totals and the tax controls depend on the kind.
        if (typeof render === 'function' && state) render();
    }

    /**
     * POST a scan, short code, or unique name to quotations.lookup.
     *
     * @param {string} code
     */
    async function lookupCode(code) {
        hideSuggestions();
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

    function showScanError(message) {
        hideSuggestions();
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
     * Jewellery is unique per ticket; rescanning a stone packet increments qty.
     *
     * @param {object} line
     */
    function addLine(line) {
        if (line.line_type === 'jewellery_item') {
            const exists = state.lines.some((l) => l.jewellery_item_id === line.jewellery_item_id);
            if (exists) {
                showScanError(t(':code is already on this ticket.', { code: line.item_code_snapshot }));
                return;
            }
            const rate = config.rateMap?.[line.purity_id_snapshot];
            if (rate) line.gold_rate_applied = rate;
            line.making_charge_type = line.making_charge_type || 'none';
            line.making_charge_value = line.making_charge_value || '0';
            state.lines.push(line);
        } else if (line.weighed) {
            // A weighed stone: one line, and the cursor goes to its grams box so the
            // cashier is asked how many grams.
            const existing = state.lines.findIndex((l) => Number(l.stone_id) === Number(line.stone_id));
            if (existing >= 0) {
                showScanError(t(':code is already on this ticket. Change its grams.', { code: line.item_code_snapshot }));
                pendingGramsFocus = existing;
                render();
                return;
            }
            if (remainingForLine(line) <= 0) {
                showScanError(t(':code has no stock on hand.', { code: line.item_code_snapshot }));
                return;
            }
            line.weight_grams = '';
            state.lines.push(line);
            pendingGramsFocus = state.lines.length - 1;
        } else {
            if (remainingForStone(line.stone_id, line.available_quantity) < 1) {
                showScanError(t(':code has no remaining stock on this ticket.', { code: line.item_code_snapshot }));
                return;
            }
            const idx = state.lines.findIndex((l) => Number(l.stone_id) === Number(line.stone_id));
            if (idx >= 0) {
                state.lines[idx].quantity = (parseInt(state.lines[idx].quantity, 10) || 1) + 1;
            } else {
                state.lines.push(line);
            }
        }
        render();
    }

    /**
     * Fill the customer fields from a registry row. The phone number is locked
     * (it is the identity); name, email, NIE and address stay editable and are
     * saved to the record with the ticket.
     *
     * @param {{uuid: string, name?: string, phone?: string, email?: string}} customer
     */
    function applyCustomer(customer) {
        state.customer_uuid = customer.uuid;
        state.customerLocked = true;
        state.customer_code = customer.customer_code || '';
        state.walkin_name = customer.name || '';
        state.walkin_phone = customer.phone || '';
        state.walkin_email = customer.email || '';
        state.walkin_nie = customer.nie || '';
        state.walkin_address = customer.address || '';

        if (els.phone) {
            els.phone.value = state.walkin_phone;
            els.phone.readOnly = true;
        }
        // The number is how the person was found, so it stays; every other field
        // can be corrected here and is saved to their record when the ticket is.
        if (els.name) {
            els.name.value = state.walkin_name;
            els.name.readOnly = false;
            els.name.required = true;
        }
        if (els.email) {
            els.email.value = state.walkin_email;
            els.email.readOnly = false;
        }
        if (els.nie) {
            els.nie.value = state.walkin_nie;
            els.nie.readOnly = false;
        }
        if (els.address) {
            els.address.value = state.walkin_address;
            els.address.readOnly = false;
        }

        setCustomerChip('existing');
    }

    /**
     * Unlock every customer field so the next number can start a new record.
     *
     * @param {'idle'|'new'} mode
     */
    function unlockCustomer(mode = 'idle') {
        state.customer_uuid = null;
        state.customerLocked = false;
        state.customer_code = '';

        [els.phone, els.name, els.email, els.nie, els.address].forEach((field) => {
            if (!field) return;
            field.readOnly = false;
            field.value = '';
            field.classList.remove('is-invalid');
        });

        if (els.name) els.name.required = mode === 'new';
        state.walkin_name = '';
        state.walkin_phone = '';
        state.walkin_email = '';
        state.walkin_nie = '';
        state.walkin_address = '';

        hideCustomerSuggestions();
        setCustomerChip(mode === 'new' ? 'new' : 'idle');
        render();
    }

    /**
     * Status chip next to the customer fields.
     *
     * @param {'idle'|'existing'|'new'} kind
     */
    function setCustomerChip(kind) {
        if (!els.chip) return;
        els.chip.classList.remove('pos-chip--existing', 'pos-chip--new');
        els.customerLocked?.classList.toggle('is-hidden', kind !== 'existing');
        const who = identityGroup === 'creditor' ? 'creditor' : 'customer';
        if (kind === 'existing') {
            const code = state.customer_code ? ` · ${state.customer_code}` : '';
            els.chip.textContent = `Existing${code}`;
            els.chip.classList.add('pos-chip--existing');
        } else if (kind === 'new') {
            els.chip.textContent = `New ${who}`;
            els.chip.classList.add('pos-chip--new');
        } else {
            els.chip.textContent = `No ${who}`;
        }
    }

    /**
     * Payload for store / invoice. Snapshots are rebuilt server-side.
     *
     * @returns {object}
     */
    function buildFormPayload() {
        const existing = Boolean(state.customer_uuid);

        return {
            customer_uuid: existing ? state.customer_uuid : null,
            customer_id: null,
            // For a loaded person these carry any corrections to save on their record.
            walkin_name: (els.name?.value || '').trim(),
            walkin_phone: existing ? '' : (els.phone?.value || '').trim(),
            walkin_email: (els.email?.value || '').trim(),
            walkin_nie: (els.nie?.value || '').trim(),
            walkin_address: (els.address?.value || '').trim(),
            update_customer: existing,
            quotation_date: state.quotation_date,
            discount_type: state.discount_on ? state.discount_type : 'none',
            discount_value: state.discount_on ? state.discount_value : '0',
            tax_type: 'none',
            tax_rate: 0,
            // Tax is on when IVA or a recargo is. The IVA key is always sent (null =
            // none), so a recargo on its own does not pull in the default IVA.
            apply_tax: Boolean(state.apply_tax || state.recargo_uuid),
            tax_rate_uuid: state.apply_tax ? (state.iva_uuid || null) : null,
            recargo_rate_uuid: state.recargo_uuid || null,
            notes: state.notes,
            lines: state.lines.map((line) => serializeLine(line)),
        };
    }

    /**
     * Strip display-only snapshot fields from one ticket line.
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
                quantity: line.weighed ? 1 : line.quantity,
                // Grams for a weighed stone (its price field is then the rate per gram).
                weight_grams: line.weighed ? (line.weight_grams || null) : null,
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
     * Phone + name (new) or a looked-up uuid. Email is optional.
     *
     * @returns {boolean}
     */
    function validateCustomer() {
        if (!els.phone || !(window.AnnigemsForm?.validateField?.(els.phone) ?? Boolean(els.phone.value.trim()))) {
            els.phone?.focus();
            return false;
        }

        if (els.name) {
            els.name.required = true;
            if (!(window.AnnigemsForm?.validateField?.(els.name) ?? Boolean(els.name.value.trim()))) {
                els.name.focus();
                return false;
            }
        }

        if (els.email && els.email.value.trim()) {
            if (!(window.AnnigemsForm?.validateField?.(els.email) ?? true)) {
                els.email.focus();
                return false;
            }
        }

        return true;
    }

    /**
     * Map Laravel field errors onto the customer card. Line errors go to a toast.
     *
     * @param {Record<string, string[]|string>} errors
     * @param {string} [fallback]
     */
    function showErrors(errors, fallback) {
        const map = {
            walkin_phone: els.phone,
            walkin_name: els.name,
            walkin_email: els.email,
            walkin_nie: els.nie,
            walkin_address: els.address,
            discount_value: els.discountValue,
        };

        let mapped = false;
        Object.entries(errors || {}).forEach(([name, messages]) => {
            const field = map[name];
            const message = Array.isArray(messages) ? messages[0] : messages;
            if (field && message) {
                window.AnnigemsForm?.validateField?.(field);
                field.classList.add('is-invalid');
                const container = field.closest('[data-field]') || field.parentElement;
                const errorEl = container?.querySelector(`[data-error-for="${field.name}"]`);
                if (errorEl) {
                    errorEl.textContent = message;
                    errorEl.classList.remove('is-hidden', 'hidden');
                }
                mapped = true;
            }
        });

        const leftover = Object.entries(errors || {})
            .filter(([name]) => !map[name])
            .flatMap(([, messages]) => (Array.isArray(messages) ? messages : [messages]))
            .filter(Boolean);

        if (leftover.length) {
            window.AnnigemsAlert?.notifyError([...new Set(leftover)].join(' '));
        } else if (!mapped) {
            window.AnnigemsAlert?.notifyError(fallback || t('Please check the highlighted fields.'));
        }
    }

    let printGeneration = 0;

    /**
     * Hidden iframe that loads quotations.print / invoices.print.
     *
     * @returns {HTMLIFrameElement|null}
     */
    function printFrame() {
        return document.getElementById('pos-print-frame');
    }

    /**
     * Wait for stylesheet fonts and images inside the print document.
     *
     * @param {Document} doc
     * @returns {Promise<void>}
     */
    function waitForPrintAssets(doc) {
        const fonts = doc.fonts?.ready ?? Promise.resolve();
        const images = [...doc.images].map((img) => {
            if (img.complete) {
                return Promise.resolve();
            }

            return new Promise((resolve) => {
                img.addEventListener('load', resolve, { once: true });
                img.addEventListener('error', resolve, { once: true });
            });
        });

        return Promise.all([fonts, ...images]).then(() => undefined);
    }

    /**
     * Load the saved document into the hidden iframe and open the print dialog.
     * GET quotations.print or invoices.print — HTML sheet, same origin.
     *
     * @param {string} printUrl
     */
    function printDocument(printUrl) {
        const frame = printFrame();
        if (!frame) {
            window.AnnigemsAlert?.notifyError(t('Document saved. Print could not start.'));
            return;
        }

        const generation = ++printGeneration;
        frame.onload = async () => {
            if (generation !== printGeneration) {
                return;
            }

            const win = frame.contentWindow;
            const doc = frame.contentDocument;
            if (!win || !doc) {
                window.AnnigemsAlert?.notifyError(t('Document saved. Print could not start.'));
                return;
            }

            try {
                await waitForPrintAssets(doc);
                win.focus();
                win.print();
            } catch (error) {
                console.error('pos: print failed', error);
                window.AnnigemsAlert?.notifyError(t('Document saved. Print could not start.'));
            }
        };

        frame.src = printUrl;
    }

    /**
     * Clear the ticket and open the saved document's detail page, where it can
     * be printed. Falls back to printing in place when no detail address came back.
     *
     * @param {{show_url?: string, print_url?: string}} data
     * @param {string} message
     */
    function finishAndPrint(data, message) {
        window.AnnigemsAlert?.notifySuccess(message);
        resetTicket();
        // Nothing prints by itself: the saved document opens on its detail page,
        // which has the Print button.
        const target = data?.show_url;
        if (target) {
            state.lines = [];
            window.location.href = target;
        } else if (data?.print_url) {
            printDocument(data.print_url);
        }
    }

    /**
     * Clear customer + lines without leaving POS. Also refreshes the catalog
     * so the saved document's stock is gone from the next ticket's dropdown.
     */
    function resetTicket() {
        state = emptyState();
        setPayState('paid');
        [els.phone, els.name, els.email, els.nie, els.address].forEach((field) => {
            if (!field) return;
            field.value = '';
            field.readOnly = false;
            field.classList.remove('is-invalid');
        });
        if (els.name) els.name.required = false;
        if (els.discountType) els.discountType.value = 'percentage';
        if (els.discountValue) els.discountValue.value = '';
        if (els.notes) els.notes.value = '';
        hideCustomerSuggestions();
        // The chosen document type stays for the next ticket.
        setDocumentKind(documentKind);
        setCustomerChip('idle');
        clearScanError();
        render();
        loadCatalog();
        loadCustomers();
        els.phone?.focus();
    }

    /**
     * POST quotations.store as JSON and print.
     *
     * @param {HTMLButtonElement} button
     */
    async function generateQuotation(button) {
        if (!validateTicket()) return;
        if (!state.lines.length) {
            window.AnnigemsAlert?.notifyError(t('Add at least one item to the quotation.'));
            return;
        }

        setDocumentKind('quotation');

        const loading = window.AnnigemsButton?.startButtonLoading(button, t('Saving…'));
        const { ok, json } = await fetchJson(routes.store, {
            method: 'POST',
            body: JSON.stringify(buildFormPayload()),
        });
        loading?.restore();

        if (ok) {
            finishAndPrint(json.data, json.message || t('Quotation saved.'));
            refreshDocumentNumbers();
        } else if (json.errors && Object.keys(json.errors).length) {
            showErrors(json.errors, json.message);
        } else {
            window.AnnigemsAlert?.notifyError(json.message || t('The quotation could not be saved.'));
        }
    }

    /**
     * POST performas.store against a creditor: the picked one, or the new one
     * the phone and name describe (saved as a creditor). No discount, no tax.
     *
     * @param {HTMLButtonElement} button
     */
    async function generatePerforma(button) {
        if (!validateTicket()) return;
        // An edited performa may keep only its locked (paid or returned) lines.
        if (!state.lines.length && !(editing && editing.locked.length)) {
            window.AnnigemsAlert?.notifyError(t('Add at least one item to the performa.'));
            return;
        }

        setDocumentKind('performa');

        const loading = window.AnnigemsButton?.startButtonLoading(button, t('Saving…'));
        const { ok, json } = await fetchJson(editing ? routes.performaUpdate : routes.performa, {
            method: editing ? 'PUT' : 'POST',
            body: JSON.stringify({
                ...buildFormPayload(),
                creditor_uuid: state.customer_uuid || null,
                performa_date: state.quotation_date,
                discount_type: 'none',
                discount_value: 0,
                apply_tax: false,
            }),
        });
        loading?.restore();

        if (ok && editing) {
            // Back to the performa's page, where it can be printed.
            window.AnnigemsAlert?.notifySuccess(json.message || t('Performa updated.'));
            state.lines = [];
            window.location.href = json.data?.show_url || json.data?.print_url;
        } else if (ok) {
            finishAndPrint(json.data, json.message || t('Performa issued.'));
            refreshDocumentNumbers();
            loadCreditors();
        } else if (json.errors && Object.keys(json.errors).length) {
            showErrors(json.errors, json.message);
        } else {
            window.AnnigemsAlert?.notifyError(json.message || t('The performa could not be saved.'));
        }
    }

    /**
     * POST delivery-notes.store: no payment, no tax. The stock leaves with it.
     *
     * @param {HTMLButtonElement} button
     */
    async function generateDeliveryNote(button) {
        if (!validateTicket()) return;
        if (!state.lines.length) {
            window.AnnigemsAlert?.notifyError(t('Add at least one item to the delivery note.'));
            return;
        }

        setDocumentKind('delivery_note');

        if (editingDoc) {
            await saveEditedDocument(button);
        } else if (state.payment_status === 'paid') {
            openPayDialog('delivery_note');
        } else {
            await postDeliveryNote(button, { payment_status: 'pending' });
        }
    }

    /**
     * POST delivery-notes.store with the chosen payment (or none, when pending).
     *
     * @param {HTMLElement|null} button
     * @param {{payment_status: string, payment_method?: string, payment_reference?: string}} payment
     * @param {Function} [afterOk]
     */
    async function postDeliveryNote(button, payment, afterOk) {
        setDocumentKind('delivery_note');

        const loading = window.AnnigemsButton?.startButtonLoading(button, t('Saving…'));
        const { ok, json } = await fetchJson(routes.deliveryNote, {
            method: 'POST',
            body: JSON.stringify({ ...buildFormPayload(), apply_tax: false, ...payment }),
        });
        loading?.restore();

        if (ok) {
            afterOk?.();
            finishAndPrint(json.data, json.message || t('Delivery note saved.'));
            refreshDocumentNumbers();
        } else if (json.errors && Object.keys(json.errors).length) {
            showErrors(json.errors, json.message);
        } else {
            window.AnnigemsAlert?.notifyError(json.message || t('The delivery note could not be saved.'));
        }
    }

    /**
     * POST quotations.invoice after payment is chosen.
     *
     * @param {HTMLFormElement} form
     */
    async function generateInvoice(form) {
        if (!validateTicket()) {
            window.AnnigemsModal?.closeModal('pos-invoice-modal');
            return;
        }
        if (!state.lines.length) {
            window.AnnigemsModal?.closeModal('pos-invoice-modal');
            window.AnnigemsAlert?.notifyError(payTarget === 'delivery_note' ? t('Add at least one item to the delivery note.') : t('Add at least one item to the invoice.'));
            return;
        }

        const paymentSelect = form.querySelector('[name="payment_method"]');
        const paymentOk = paymentSelect
            ? (window.AnnigemsForm?.validateField?.(paymentSelect) ?? Boolean(paymentSelect.value))
            : false;
        if (!paymentOk) {
            return;
        }

        const payment = {
            payment_status: 'paid',
            payment_method: paymentSelect.value,
            payment_reference: form.querySelector('[name="payment_reference"]')?.value || '',
        };
        const submitBtn = form.querySelector('[type="submit"]');

        if (payTarget === 'delivery_note') {
            await postDeliveryNote(submitBtn, payment, () => {
                window.AnnigemsModal?.closeModal('pos-invoice-modal');
                form.reset();
            });
        } else {
            await postInvoice(submitBtn, payment, () => {
                window.AnnigemsModal?.closeModal('pos-invoice-modal');
                form.reset();
            });
        }
    }

    /**
     * Open the payment dialog for an invoice or a delivery note.
     *
     * @param {'invoice'|'delivery_note'} target
     */
    function openPayDialog(target) {
        payTarget = target;
        const note = target === 'delivery_note';
        const title = document.getElementById('pos-invoice-modal-title');
        if (title) title.textContent = note ? t('Take payment for the delivery note') : t('Take payment');
        const help = document.querySelector('[data-pay-help]');
        if (help) {
            help.textContent = note
                ? t('Confirming creates the delivery note, takes the stock out and records the payment.')
                : t('Confirming creates the invoice, marks stock sold and records the payment.');
        }
        const confirm = document.querySelector('[data-pay-confirm]');
        if (confirm) confirm.textContent = note ? t('Confirm & create delivery note') : t('Confirm & create invoice');
        window.AnnigemsModal?.openModal('pos-invoice-modal');
    }

    /**
     * POST quotations.invoice with the chosen payment (or none, when pending).
     *
     * @param {HTMLElement|null} button
     * @param {{payment_status: string, payment_method?: string, payment_reference?: string}} payment
     * @param {Function} [afterOk]
     */
    async function postInvoice(button, payment, afterOk) {
        setDocumentKind('invoice');

        const loading = window.AnnigemsButton?.startButtonLoading(button, t('Saving…'));
        const payload = { ...buildFormPayload(), ...payment };
        const { ok, json } = await fetchJson(routes.invoice, {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        loading?.restore();

        if (ok) {
            afterOk?.();
            finishAndPrint(json.data, json.message || t('Invoice created.'));
            refreshDocumentNumbers();
        } else if (json.errors && Object.keys(json.errors).length) {
            showErrors(json.errors, json.message);
        } else {
            window.AnnigemsAlert?.notifyError(json.message || t('The invoice could not be created.'));
        }
    }

    els.phone?.addEventListener('input', () => {
        // Editing the number drops the previous match. The list is filtered
        // from the page-load cache, so this does not call the server.
        state.customer_uuid = null;
        state.customerLocked = false;
        state.customer_code = '';
        if (els.name) {
            els.name.readOnly = false;
            els.name.required = true;
        }
        if (els.email) els.email.readOnly = false;
        if (els.nie) els.nie.readOnly = false;
        if (els.address) els.address.readOnly = false;
        els.customerLocked?.classList.add('is-hidden');
        state.walkin_phone = els.phone.value;
        filterCustomers(els.phone.value);
    });

    els.name?.addEventListener('input', () => {
        state.walkin_name = els.name.value;
    });
    els.nie?.addEventListener('input', () => {
        state.walkin_nie = els.nie.value;
    });
    els.address?.addEventListener('input', () => {
        state.walkin_address = els.address.value;
    });
    els.email?.addEventListener('input', () => {
        state.walkin_email = els.email.value;
    });

    els.customerClear?.addEventListener('click', () => {
        unlockCustomer('idle');
        els.phone?.focus();
    });

    els.issueDate?.addEventListener('change', () => {
        state.quotation_date = els.issueDate.value || state.quotation_date;
        render();
    });

    els.clearLines?.addEventListener('click', () => {
        if (!state.lines.length) return;
        state.lines = [];
        clearScanError();
        render();
    });

    els.notes?.addEventListener('input', () => {
        state.notes = els.notes.value;
    });

    els.toggleDiscount?.addEventListener('click', () => {
        state.discount_on = true;
        state.discount_type = els.discountType?.value || 'percentage';
        state.discount_value = els.discountValue?.value || '0';
        render();
        els.discountValue?.focus();
    });

    els.clearDiscount?.addEventListener('click', () => {
        state.discount_on = false;
        state.discount_type = 'none';
        state.discount_value = '0';
        if (els.discountValue) els.discountValue.value = '';
        render();
    });

    els.discountType?.addEventListener('change', () => {
        state.discount_type = els.discountType.value;
        render();
    });
    els.discountValue?.addEventListener('change', () => {
        state.discount_value = els.discountValue.value || '0';
        render();
    });

    els.toggleTax?.addEventListener('click', () => {
        state.apply_tax = true;
        render();
    });

    els.ivaSelect?.addEventListener('change', () => {
        state.iva_uuid = els.ivaSelect.value;
        render();
    });

    els.toggleRecargo?.addEventListener('click', () => {
        state.recargo_uuid = els.recargoSelect?.value || config.recargoRates?.[0]?.uuid || '';
        render();
    });

    els.recargoSelect?.addEventListener('change', () => {
        state.recargo_uuid = els.recargoSelect.value;
        render();
    });

    els.clearRecargo?.addEventListener('click', () => {
        state.recargo_uuid = '';
        render();
    });

    els.clearTax?.addEventListener('click', () => {
        state.apply_tax = false;
        render();
    });

    els.generateQuote?.addEventListener('click', (event) => {
        event.preventDefault();
        generateQuotation(els.generateQuote);
    });

    els.generatePerforma?.addEventListener('click', (event) => {
        event.preventDefault();
        generatePerforma(els.generatePerforma);
    });

    els.generateDeliveryNote?.addEventListener('click', (event) => {
        event.preventDefault();
        generateDeliveryNote(els.generateDeliveryNote);
    });

    /**
     * Mark the ticket Pending or Paid; the switch follows the state.
     *
     * @param {'pending'|'paid'} value
     */
    function setPayState(value) {
        state.payment_status = value;
        root.querySelectorAll('[data-pay-state]').forEach((option) => {
            const on = option.dataset.payState === value;
            option.classList.toggle('is-on', on);
            option.setAttribute('aria-checked', on ? 'true' : 'false');
        });
    }

    root.querySelectorAll('[data-pay-state]').forEach((option) => {
        option.addEventListener('click', () => setPayState(option.dataset.payState));
    });

    /**
     * PUT the ticket back onto the invoice or delivery note being edited, then
     * open its page. Editing never records a payment.
     *
     * @param {HTMLElement|null} button
     */
    async function saveEditedDocument(button) {
        if (!validateTicket()) return;
        if (!state.lines.length) {
            window.AnnigemsAlert?.notifyError(t('Add at least one item.'));
            return;
        }

        const loading = window.AnnigemsButton?.startButtonLoading(button, t('Saving…'));
        const { ok, json } = await fetchJson(editingDoc.update_url, {
            method: 'PUT',
            body: JSON.stringify({
                ...buildFormPayload(),
                ...(editingDoc.type === 'delivery_note' ? { apply_tax: false } : {}),
            }),
        });
        loading?.restore();

        if (ok) {
            window.AnnigemsAlert?.notifySuccess(json.message || 'Saved.');
            state.lines = [];
            window.location.href = json.data?.show_url;
        } else if (json.errors && Object.keys(json.errors).length) {
            showErrors(json.errors, json.message);
        } else {
            window.AnnigemsAlert?.notifyError(json.message || t('It could not be saved.'));
        }
    }

    /**
     * Load an invoice or delivery note into the ticket: customer, date, notes,
     * discount, IVA and lines. The kind is fixed, there is no payment choice,
     * and the generate button saves the changes.
     */
    function openForEditingDocument() {
        documentNumbers = { ...documentNumbers, [editingDoc.type]: editingDoc.number };

        state.quotation_date = editingDoc.date || state.quotation_date;
        if (els.issueDate) els.issueDate.value = state.quotation_date;
        state.notes = editingDoc.notes || '';
        if (els.notes) els.notes.value = state.notes;
        state.lines = (editingDoc.lines || []).map((line) => ({ ...line }));

        state.discount_on = Boolean(editingDoc.discount?.on);
        state.discount_type = editingDoc.discount?.type && editingDoc.discount.type !== 'none' ? editingDoc.discount.type : 'percentage';
        state.discount_value = editingDoc.discount?.value || '0';
        if (els.discountType) els.discountType.value = state.discount_type;
        if (els.discountValue) els.discountValue.value = state.discount_on ? state.discount_value : '';

        state.apply_tax = Boolean(editingDoc.tax?.apply);
        if (editingDoc.tax?.iva_uuid) state.iva_uuid = editingDoc.tax.iva_uuid;
        state.recargo_uuid = editingDoc.tax?.recargo_uuid || '';

        if (editingDoc.customer) {
            applyCustomer(editingDoc.customer);
        }

        // Nothing but this kind of document can be chosen, and no payment is taken here.
        els.kindOptions?.forEach((option) => {
            option.classList.toggle('is-hidden', option.dataset.kind !== editingDoc.type);
        });
        root.querySelector('[data-pay-state-group]')?.classList.add('is-hidden');
        root.querySelectorAll('[data-generate-label]').forEach((label) => {
            label.textContent = t('Save Changes');
        });

        const banner = root.querySelector('[data-edit-banner]');
        if (banner) {
            banner.innerHTML = `<strong>${t('Editing :number.', { number: escapeHtml(editingDoc.number) })}</strong> Changing a line puts its stock back and takes it out again. Once a payment is recorded it can no longer be edited.`;
            banner.classList.remove('is-hidden');
        }
        render();
    }

    els.generateInvoice?.addEventListener('click', (event) => {
        event.preventDefault();
        if (!validateTicket()) return;
        if (!state.lines.length) {
            window.AnnigemsAlert?.notifyError(t('Add at least one item to the invoice.'));
            return;
        }
        setDocumentKind('invoice');
        if (editingDoc) {
            saveEditedDocument(els.generateInvoice);
        } else if (state.payment_status === 'paid') {
            openPayDialog('invoice');
        } else {
            postInvoice(els.generateInvoice, { payment_status: 'pending' });
        }
    });

    els.kindOptions?.forEach((option) => {
        option.addEventListener('click', () => {
            if (option.disabled) return;
            setDocumentKind(['invoice', 'delivery_note', 'performa'].includes(option.dataset.kind) ? option.dataset.kind : 'quotation');
        });
    });

    const invoiceForm = document.getElementById('pos-invoice-modal-form');
    invoiceForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        generateInvoice(invoiceForm);
    });

    // Payment cards write the hidden select the invoice request already reads.
    document.querySelectorAll('[data-pay-method]').forEach((button) => {
        button.addEventListener('click', () => {
            const select = invoiceForm?.querySelector('[name="payment_method"]');
            if (select) select.value = button.dataset.payMethod || '';
            document.querySelectorAll('[data-pay-method]').forEach((other) => {
                const on = other === button;
                other.classList.toggle('is-on', on);
                other.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        });
    });

    /**
     * Rate, quantity, and customer fields must keep their own keystrokes.
     *
     * @param {EventTarget|null} el
     */
    function isProtectedTarget(el) {
        if (!(el instanceof HTMLElement)) return false;
        if (el === els.scanInput) return false;

        return el.matches('input, textarea, select, [contenteditable="true"]');
    }

    let catalog = [];
    let suggestions = [];
    let activeSuggest = 0;
    let catalogSeq = 0;

    /**
     * On-hand qty from the page-load catalog (fallback: the line snapshot).
     *
     * @param {number|string} stoneId
     * @param {number|string} [fallback]
     * @returns {number}
     */
    function stoneOnHand(stoneId, fallback, weighed = false) {
        const row = catalog.find((item) => (
            item.line_type === 'loose_stone' && Number(item.line?.stone_id) === Number(stoneId)
        ));

        if (weighed) {
            return Number(row?.line?.available_grams ?? fallback ?? 0);
        }

        return Number(row?.line?.available_quantity ?? fallback ?? 0);
    }

    /**
     * Grams or pieces on hand for a ticket line, in the unit the stone is sold in.
     *
     * @param {object} line
     * @returns {number}
     */
    function lineOnHand(line) {
        return line.weighed
            ? stoneOnHand(line.stone_id, line.available_grams, true)
            : stoneOnHand(line.stone_id, line.available_quantity);
    }

    /**
     * Grams of this weighed stone already on the ticket, optionally leaving out one line.
     *
     * @param {number|string} stoneId
     * @param {object|null} [except]
     * @returns {number}
     */
    function ticketStoneGrams(stoneId, except = null) {
        return state.lines
            .filter((line) => line.weighed && line !== except && Number(line.stone_id) === Number(stoneId))
            .reduce((sum, line) => sum + (parseFloat(line.weight_grams) || 0), 0);
    }

    /**
     * What can still be added of this stone: grams for a weighed one, pieces otherwise.
     *
     * @param {object} line
     * @returns {number}
     */
    function remainingForLine(line) {
        if (line.weighed) {
            return Math.max(0, Math.round((lineOnHand(line) - ticketStoneGrams(line.stone_id)) * 1000) / 1000);
        }

        return remainingForStone(line.stone_id, line.available_quantity);
    }

    /**
     * Grams as a short figure: 5, 5.5, 5.125 (no trailing zeros).
     *
     * @param {number|string} value
     * @returns {string}
     */
    function formatGrams(value) {
        const n = Math.round((Number(value) || 0) * 1000) / 1000;

        return String(n);
    }

    /**
     * Keep typed grams positive and within what is left of the lot.
     *
     * @param {object} line
     * @param {string|number} value
     * @returns {string}
     */
    function clampGrams(line, value) {
        let grams = Math.round((parseFloat(value) || 0) * 1000) / 1000;
        if (grams <= 0) return '';

        const room = Math.round((lineOnHand(line) - ticketStoneGrams(line.stone_id, line)) * 1000) / 1000;
        if (room >= 0 && grams > room) {
            grams = Math.max(0, room);
            showScanError(t(':code has only :room g in stock.', { code: line.item_code_snapshot, room: formatGrams(room) }));
        }

        return grams > 0 ? String(grams) : '';
    }

    /**
     * Every weighed stone on the ticket needs its grams before anything is saved.
     *
     * @returns {boolean}
     */
    function linesReady() {
        const missing = state.lines.findIndex((line) => line.weighed && !(parseFloat(line.weight_grams) > 0));
        if (missing < 0) return true;

        window.AnnigemsAlert?.notifyError(t('Enter how many grams of :code.', { code: state.lines[missing].item_code_snapshot }));
        pendingGramsFocus = missing;
        render();

        return false;
    }

    /**
     * The customer (or creditor) is filled in and every line is complete.
     *
     * @returns {boolean}
     */
    function validateTicket() {
        return validateCustomer() && linesReady();
    }

    /**
     * How many of this stone are already on the live ticket.
     *
     * @param {number|string} stoneId
     * @returns {number}
     */
    function ticketStoneQty(stoneId) {
        return state.lines
            .filter((line) => Number(line.stone_id) === Number(stoneId))
            .reduce((sum, line) => sum + (parseInt(line.quantity, 10) || 0), 0);
    }

    /**
     * Catalog on-hand minus qty already on this ticket.
     *
     * @param {number|string} stoneId
     * @param {number|string} [fallback]
     * @returns {number}
     */
    function remainingForStone(stoneId, fallback) {
        return Math.max(0, stoneOnHand(stoneId, fallback) - ticketStoneQty(stoneId));
    }

    /**
     * Keep a typed stone qty between 1 and what the cache still has.
     *
     * @param {object} line
     * @param {string|number} value
     * @returns {number}
     */
    function clampStoneQuantity(line, value) {
        const max = stoneOnHand(line.stone_id, line.available_quantity);
        let qty = Math.max(1, parseInt(value, 10) || 1);
        if (max > 0 && qty > max) {
            qty = max;
            showScanError(t(':code has only :max in stock.', { code: line.item_code_snapshot, max: max }));
        }

        return qty;
    }

    /**
     * Remaining units that can still be added from this catalog row.
     * Jewellery is unique — 0 once it is already a line.
     *
     * @param {object} row
     * @returns {number}
     */
    function remainingForRow(row) {
        const line = row?.line || {};
        if (row.line_type === 'jewellery_item') {
            const id = Number(line.jewellery_item_id);

            return state.lines.some((item) => Number(item.jewellery_item_id) === id) ? 0 : 1;
        }

        return remainingForLine(line);
    }

    /**
     * Position a fixed dropdown under its field. Fixed placement keeps the
     * panel out of the scrolling canvas so it is never clipped.
     *
     * @param {HTMLElement|null} wrap
     * @param {HTMLElement|null} panel
     */
    function positionPanel(wrap, panel) {
        if (!wrap || !panel) return;
        const rect = wrap.getBoundingClientRect();
        panel.style.left = `${rect.left}px`;
        panel.style.width = `${rect.width}px`;
        panel.style.top = `${rect.bottom + 4}px`;
    }

    /**
     * Show or hide a dropdown panel and keep aria-expanded truthful.
     *
     * @param {HTMLElement|null} panel
     * @param {HTMLInputElement|null} input
     * @param {boolean} open
     */
    function togglePanel(panel, input, open) {
        if (!panel) return;
        panel.classList.toggle('is-hidden', !open);
        panel.hidden = !open;
        if (!open) panel.innerHTML = '';
        input?.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    /**
     * One typeahead row: bold title, mono meta line, trailing badge.
     *
     * @param {object} row
     * @param {number} index
     * @param {number} active
     * @returns {string}
     */
    /**
     * Up to two initials for a customer row avatar.
     *
     * @param {string} name
     * @returns {string}
     */
    function initials(name) {
        const letters = String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2);

        return letters.map((word) => word.charAt(0)).join('').toUpperCase() || '—';
    }

    function suggestRow(row, index, active) {
        const lead = row.initials
            ? `<span class="pos-suggest__lead">${escapeHtml(row.initials)}</span>`
            : (row.icon
                ? `<span class="pos-thumb"><span class="material-symbols-outlined icon icon--sm">${escapeHtml(row.icon)}</span></span>`
                : '');
        const code = row.code && row.icon
            ? `<span class="pos-suggest__code">${escapeHtml(row.code)}</span>`
            : '';
        const badge = row.badge
            ? `<span class="pos-chip${row.badgeStrong ? ' pos-chip--existing' : ''}">${row.phoneBadge ? '<span class="material-symbols-outlined icon icon--xs">call</span>' : ''}${escapeHtml(row.badge)}</span>`
            : '';

        return `<li class="pos-suggest__item${row.icon ? ' pos-suggest__cols' : ''}${index === active ? ' is-active' : ''}" role="option" aria-selected="${index === active}" data-suggest-index="${index}">
            ${lead}
            ${code}
            <span class="pos-suggest__main">
                <span class="pos-suggest__title">${escapeHtml(row.title)}</span>
                <span class="pos-suggest__meta">${escapeHtml(row.meta)}</span>
            </span>
            ${badge}
        </li>`;
    }

    /* ------------------------------------------------------ item typeahead ---- */

    /**
     * Re-filter the open item list so remaining counts stay in sync with the ticket.
     */
    function refreshSuggestionsIfOpen() {
        const term = els.scanInput?.value || '';
        if (term.trim().length < 1) {
            return;
        }

        filterCatalog(term);
    }

    /**
     * Close the item typeahead.
     */
    function hideSuggestions() {
        suggestions = [];
        activeSuggest = 0;
        togglePanel(els.scanSuggest, els.scanInput, false);
    }

    /**
     * Describe one catalog row for the dropdown: code + name on top, the facts
     * a cashier picks on below, and what is left in stock as a badge.
     * A jewellery piece is unique, so it only ever reads "Available".
     *
     * @param {object} row
     * @param {number} remaining
     * @returns {object}
     */
    function itemSuggestion(row, remaining) {
        const line = row.line || {};
        const code = row.code || line.item_code_snapshot || '';
        const name = row.title || line.name_snapshot || '';
        const isJewellery = row.line_type === 'jewellery_item';

        return {
            ...row,
            icon: isJewellery ? 'diamond' : 'grain',
            code,
            title: name,
            meta: row.meta || String(row.label || ''),
            badge: isJewellery ? t('In stock') : (line.weighed ? t(':remaining g in stock', { remaining: formatGrams(remaining) }) : t(':remaining in stock', { remaining: remaining })),
            badgeStrong: true,
        };
    }

    /**
     * Paint the item typeahead. The first row is active so Enter adds it.
     */
    function renderSuggestions() {
        if (!els.scanSuggest) return;

        if (!suggestions.length) {
            const term = (els.scanInput?.value || '').trim();
            if (!term) {
                hideSuggestions();
                return;
            }
            els.scanSuggest.innerHTML = '<li class="pos-suggest__empty">' + t('No in-stock item matches. Reserved, sold and sold-out items are hidden.') + '</li>';
            togglePanel(els.scanSuggest, els.scanInput, true);
            positionPanel(els.scanWrap, els.scanSuggest);
            return;
        }

        els.scanSuggest.innerHTML = suggestions
            .map((row, index) => suggestRow(row, index, activeSuggest))
            .join('');
        togglePanel(els.scanSuggest, els.scanInput, true);
        positionPanel(els.scanWrap, els.scanSuggest);
    }

    /**
     * Add the highlighted (or clicked) search hit, then clear the field and
     * close the list — a picked option always closes the dropdown.
     *
     * @param {number} index
     */
    function pickSuggestion(index) {
        const row = suggestions[index];
        if (!row?.line) return;
        clearScanError();
        hideSuggestions();
        if (els.scanInput) els.scanInput.value = '';
        els.scanClear?.classList.add('is-hidden');
        addLine(JSON.parse(JSON.stringify(row.line)));
        els.scanInput?.focus();
    }

    /**
     * GET quotations.search?catalog=1 — cache every addable jewellery + stone.
     */
    async function loadCatalog() {
        const seq = ++catalogSeq;
        const { ok, json } = await fetchJson(`${routes.search}?catalog=1`);
        if (seq !== catalogSeq) return;
        catalog = ok && Array.isArray(json.data?.items) ? json.data.items : [];
    }

    /**
     * Filter the cached catalog in the browser — no request per keystroke.
     *
     * @param {string} term
     * @returns {number} How many rows matched
     */
    function filterCatalog(term) {
        const needle = term.trim().toLowerCase();
        if (needle.length < 1) {
            hideSuggestions();
            return 0;
        }

        suggestions = catalog.filter((row) => {
            const haystack = String(row.haystack || row.label || '').toLowerCase();

            return haystack.includes(needle);
        }).map((row) => {
            const remaining = remainingForRow(row);
            // A weighed lot is on offer while any grams remain; a piece lot while a piece does.
            if (remaining < (row.line?.weighed ? 0.001 : 1)) {
                return null;
            }

            return itemSuggestion(row, remaining);
        }).filter(Boolean).slice(0, 20);
        activeSuggest = 0;
        renderSuggestions();

        return suggestions.length;
    }

    /**
     * Enter / Add: pick the focused suggestion, otherwise lookup the typed code.
     */
    function submitScan() {
        if (suggestions.length) {
            pickSuggestion(activeSuggest);
            return;
        }
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
            readyWrap: els.scanWrap,
            enabled: true,
            allowManualTyping: true,
            shouldSubmitScan: () => suggestions.length === 0,
        });

        els.scanInput.addEventListener('input', () => {
            els.scanClear?.classList.toggle('is-hidden', els.scanInput.value === '');
            filterCatalog(els.scanInput.value);
        });

        els.scanInput.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' && suggestions.length) {
                event.preventDefault();
                activeSuggest = (activeSuggest + 1) % suggestions.length;
                renderSuggestions();
                return;
            }
            if (event.key === 'ArrowUp' && suggestions.length) {
                event.preventDefault();
                activeSuggest = (activeSuggest - 1 + suggestions.length) % suggestions.length;
                renderSuggestions();
                return;
            }
            if (event.key === 'Escape') {
                hideSuggestions();
                return;
            }
            if (event.key !== 'Enter') return;
            event.preventDefault();
            submitScan();
        });

        els.scanSuggest?.addEventListener('mousedown', (event) => {
            const item = event.target.closest('[data-suggest-index]');
            if (!item) return;
            event.preventDefault();
            pickSuggestion(parseInt(item.dataset.suggestIndex, 10));
        });

        els.scanClear?.addEventListener('click', () => {
            els.scanInput.value = '';
            els.scanClear.classList.add('is-hidden');
            hideSuggestions();
            clearScanError();
            els.scanInput.focus();
        });

        root.querySelector('[data-scan-submit]')?.addEventListener('click', submitScan);
    }

    /* -------------------------------------------------- customer typeahead ---- */

    let customerCatalog = [];
    let creditorCatalog = [];
    let customerHits = [];
    let activeCustomer = 0;
    let customerSeq = 0;

    /**
     * Close the list under the phone field.
     */
    function hideCustomerSuggestions() {
        customerHits = [];
        activeCustomer = 0;
        togglePanel(els.customerSuggest, els.phone, false);
    }

    /**
     * One cached customer as a dropdown row.
     *
     * @param {object} customer
     * @returns {object}
     */
    function customerRow(customer) {
        return {
            customer,
            initials: initials(customer.name),
            title: customer.name || '—',
            meta: [customer.customer_code, customer.email].filter(Boolean).join(' · '),
            badge: customer.phone || '',
            phoneBadge: Boolean(customer.phone),
        };
    }

    /**
     * Paint matches under the phone field. No matches means a new customer:
     * the list closes and name plus email stay editable.
     */
    function renderCustomerSuggestions() {
        if (!els.customerSuggest) return;

        if (!customerHits.length) {
            hideCustomerSuggestions();
            return;
        }

        els.customerSuggest.innerHTML = customerHits
            .map((row, index) => suggestRow(row, index, activeCustomer))
            .join('');
        togglePanel(els.customerSuggest, els.phone, true);
        positionPanel(els.phone, els.customerSuggest);
    }

    /**
     * Filter the page-load registry. Digits match the phone even when the
     * stored number contains spaces.
     *
     * @param {string} term
     */
    function filterCustomers(term) {
        const needle = term.trim().toLowerCase();
        const digits = needle.replace(/\D/g, '');
        if (needle.length < 2) {
            hideCustomerSuggestions();
            setCustomerChip(needle.length ? 'new' : 'idle');
            return;
        }

        customerHits = (identityGroup === 'creditor' ? creditorCatalog : customerCatalog).filter((row) => (
            row.haystack.includes(needle) || (digits.length >= 2 && row.haystack.includes(digits))
        )).slice(0, 8).map((row) => customerRow(row));
        activeCustomer = 0;
        renderCustomerSuggestions();
        setCustomerChip(customerHits.length ? 'idle' : 'new');
    }

    /**
     * GET customers.search?catalog=1 — one registry payload, then local filter.
     */
    async function loadCustomers() {
        const seq = ++customerSeq;
        const { ok, json } = await fetchJson(`${routes.customerSearch}?catalog=1`);
        if (seq !== customerSeq) return;

        const rows = ok && Array.isArray(json.data?.customers) ? json.data.customers : [];
        customerCatalog = rows.map((customer) => ({
            ...customer,
            haystack: [
                customer.name,
                customer.phone,
                String(customer.phone || '').replace(/\D/g, ''),
                customer.customer_code,
                customer.email,
                customer.city,
            ].filter(Boolean).join(' ').toLowerCase(),
        }));
    }

    /**
     * GET creditors.search?catalog=1 — the branch's creditors, filtered locally
     * like customers. A creditor looks like a customer to the phone picker.
     */
    async function loadCreditors() {
        if (!routes.creditorSearch) return;

        const { ok, json } = await fetchJson(`${routes.creditorSearch}?catalog=1`);
        const rows = ok && Array.isArray(json.data?.creditors) ? json.data.creditors : [];
        creditorCatalog = rows.map((creditor) => ({
            ...creditor,
            customer_code: creditor.creditor_code,
            haystack: [
                creditor.name,
                creditor.phone,
                String(creditor.phone || '').replace(/\D/g, ''),
                creditor.creditor_code,
                creditor.email,
                creditor.city,
            ].filter(Boolean).join(' ').toLowerCase(),
        }));
    }

    /**
     * Fill the card from the highlighted (or clicked) customer, then close.
     *
     * @param {number} index
     */
    function pickCustomer(index) {
        const hit = customerHits[index];
        if (!hit?.customer) return;
        applyCustomer(hit.customer);
        hideCustomerSuggestions();
        render();
    }

    if (els.phone) {
        els.phone.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' && customerHits.length) {
                event.preventDefault();
                activeCustomer = (activeCustomer + 1) % customerHits.length;
                renderCustomerSuggestions();
                return;
            }
            if (event.key === 'ArrowUp' && customerHits.length) {
                event.preventDefault();
                activeCustomer = (activeCustomer - 1 + customerHits.length) % customerHits.length;
                renderCustomerSuggestions();
                return;
            }
            if (event.key === 'Escape') {
                hideCustomerSuggestions();
                return;
            }
            if (event.key !== 'Enter' || !customerHits.length) return;
            event.preventDefault();
            pickCustomer(activeCustomer);
        });

        els.customerSuggest?.addEventListener('mousedown', (event) => {
            const item = event.target.closest('[data-suggest-index]');
            if (!item) return;
            event.preventDefault();
            pickCustomer(parseInt(item.dataset.suggestIndex, 10));
        });
    }

    /* --------------------------------------------- shared dropdown plumbing ---- */

    // Fixed panels must follow their field when the canvas scrolls or resizes.
    ['resize', 'scroll'].forEach((event) => {
        window.addEventListener(event, () => {
            if (suggestions.length) positionPanel(els.scanWrap, els.scanSuggest);
            if (customerHits.length) positionPanel(els.phone, els.customerSuggest);
        }, true);
    });

    // Clicking anywhere outside a field + its panel closes that panel.
    document.addEventListener('click', (event) => {
        if (suggestions.length
            && !els.scanWrap?.contains(event.target)
            && !els.scanSuggest?.contains(event.target)) {
            hideSuggestions();
        }
        if (customerHits.length
            && !els.customerWrap?.contains(event.target)
            && !els.customerSuggest?.contains(event.target)) {
            hideCustomerSuggestions();
        }
    });

    /**
     * Load an issued performa into the ticket: its creditor, date, notes and the
     * lines that can still change. Paid or returned lines are listed, not editable,
     * and the creditor stays put once any exist.
     */
    function openForEditing() {
        documentNumbers = { ...documentNumbers, performa: editing.number };
        renderDocumentNumber();

        state.quotation_date = editing.performa_date || state.quotation_date;
        if (els.issueDate) els.issueDate.value = state.quotation_date;
        state.notes = editing.notes || '';
        if (els.notes) els.notes.value = state.notes;
        state.lines = (editing.lines || []).map((line) => ({ ...line }));

        if (editing.creditor) {
            applyCustomer(editing.creditor);
        }
        if (editing.creditor_locked) {
            els.customerClear?.classList.add('is-hidden');
        }

        // Nothing but this performa can be chosen here.
        els.kindOptions?.forEach((option) => {
            option.classList.toggle('is-hidden', option.dataset.kind !== 'performa');
        });
        const label = root.querySelector('[data-generate-performa-label]');
        if (label) label.textContent = t('Save Performa Changes');

        const banner = root.querySelector('[data-edit-banner]');
        if (banner) {
            const locked = (editing.locked || [])
                .map((row) => `<li>${escapeHtml(row.code)} — ${escapeHtml(row.name)} (${escapeHtml(row.summary)})</li>`)
                .join('');
            banner.innerHTML = `<strong>${t('Editing :number.', { number: escapeHtml(editing.number) })}</strong> Changing a line puts its stock back and takes it out again.`
                + (locked ? `<br>${t('These lines are already paid or returned and stay as they are:')}<ul class="pos-edit-banner__locked">${locked}</ul>` : '');
            banner.classList.remove('is-hidden');
        }
        render();
    }

    window.addEventListener('beforeunload', (event) => {
        if (!state.lines.length) return;
        event.preventDefault();
        event.returnValue = '';
    });

    setDocumentKind(documentKind);
    if (editing) {
        openForEditing();
    }
    if (editingDoc) {
        openForEditingDocument();
    }
    // Arriving from a creditor's page: that creditor is already the one billed.
    if (config.initialCreditor && documentKind === 'performa') {
        applyCustomer(config.initialCreditor);
    }
    render();
    loadCatalog();
    loadCustomers();
}
