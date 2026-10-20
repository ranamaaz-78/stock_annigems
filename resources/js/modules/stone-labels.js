/**
 * Label print dialog for stone packets.
 *
 * Opens on top of the page (no new window). The operator picks the label
 * stock, what to show, how many copies, and sees the first printed page as it
 * will come out. Print draws every page in a hidden frame and prints that, so
 * the page behind the dialog is never part of the print.
 *
 * Label geometry is in millimetres. Sheets (A4 stickers) are laid out in a
 * grid of fixed labels; rolls print one label per page, sized to the label.
 */

import { t } from './i18n';

/** Company name printed on each label; read from the dialog when it is bound. */
let companyName = '';

/** What the open dialog prints: 'barcode', 'qr', or 'both' (a barcode label and a QR label each). */
let labelKind = 'barcode';

/** Mobile-safe localStorage key for the last choices. */
const STORAGE_KEY = 'annigems.stones.labelOptions';

/**
 * Label stock choices. Sheets carry their grid (columns, rows, gaps); rolls
 * are one label per page.
 *
 * @type {Array<{group: string, items: Array<object>}>}
 */
const PRESETS = (() => {
    const sizes = [[50, 30], [25, 25], [35, 35]];
    // The 50 x 30 label holds two 25 x 30 barcodes side by side.
    const split = ([w, h]) => (w === 50 && h === 30 ? 2 : 1);
    const roll = ([w, h]) => ({ id: `roll-${w}x${h}`, name: t('Roll :w × :h mm', { w: w, h: h }), kind: 'roll', w, h, split: split([w, h]) });

    // A4 sheets: as many labels as fit with a 5 mm margin, packed edge to edge.
    const sheet = ([w, h]) => ({
        id: `a4-${w}x${h}`,
        name: t('A4 :w × :h mm', { w: w, h: h }),
        kind: 'sheet',
        w,
        h,
        split: split([w, h]),
        cols: Math.floor(200 / w),
        rows: Math.floor(287 / h),
        gapX: 0,
        gapY: 0,
    });

    return [
        { group: t('Roll labels'), items: sizes.map(roll) },
        { group: t('A4 sheets'), items: sizes.map(sheet) },
    ];
})();

/** Text size and barcode height choices. */
const TEXT_SCALE = { small: 0.85, normal: 1, large: 1.25 };
const BAR_SHARE = { short: 0.5, normal: 0.75, tall: 1 };

/** Defaults for a first visit. */
const DEFAULTS = {
    preset: 'roll-50x30',
    text: 'normal',
    bars: 'normal',
    copies: 1,
    showPrice: true,
    showName: true,
    border: false,
    customW: 50,
    customH: 30,
};

/**
 * Text that is safe inside HTML.
 *
 * @param {unknown} value
 * @returns {string}
 */
function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);
}

/**
 * @param {number} value
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

/**
 * @param {string} id
 * @returns {object|undefined}
 */
function findPreset(id) {
    return PRESETS.flatMap((group) => group.items).find((item) => item.id === id);
}

/**
 * Print options from storage, falling back to the defaults. Private-mode
 * browsers throw on storage access, which must never break the dialog.
 *
 * @returns {object}
 */
function loadOptions() {
    try {
        return { ...DEFAULTS, ...JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}') };
    } catch (error) {
        return { ...DEFAULTS };
    }
}

/**
 * @param {object} options
 */
function saveOptions(options) {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(options));
    } catch (error) {
        // Remembering the choice is a convenience only.
    }
}

/**
 * The label stock in use: the chosen preset, or the custom size typed in.
 *
 * @param {object} options
 * @returns {object}
 */
function stockFor(options) {
    const preset = findPreset(options.preset) || findPreset(DEFAULTS.preset);

    if (!preset.custom) {
        return preset;
    }

    return {
        ...preset,
        w: clamp(Number(options.customW) || 50, 10, 200),
        h: clamp(Number(options.customH) || 30, 8, 200),
    };
}

/**
 * HTML of one label, sized in millimetres to the stock.
 *
 * Text and bar sizes follow the label's height, then the text-size and
 * barcode-height choices, so a small label stays readable and a large one
 * does not look empty.
 *
 * @param {object} stone
 * @param {object} stock
 * @param {object} options
 * @returns {string}
 */
function labelHtml(stone, stock, options) {
    if ((stone._kind || 'barcode') === 'qr') {
        return qrLabelHtml(stone, stock, options);
    }

    const { w, h } = stock;
    const pad = clamp(Math.min(w, h) * 0.06, 0.6, 2);
    const scale = TEXT_SCALE[options.text] || 1;
    const font = clamp(h * 0.085, 1.5, 3.2) * scale;
    const companyFont = font * 1.1;
    const numberFont = font * 1.4;

    // A long name wraps onto a second line instead of being cut off; the
    // price sits on its own line underneath.
    const quiet = clamp(w * 0.06, 1.5, 5);
    const name = options.showName ? String(stone.name ?? '') : '';
    const perLine = Math.max(6, Math.floor((w - quiet) / (font * 0.58)));
    const nameLines = name ? Math.min(2, Math.ceil(name.length / perLine)) : 0;
    const price = options.showPrice ? String(stone.price ?? '') : '';

    // Text lines decide how much height is left for the bars.
    const lineHeights = [
        companyName ? companyFont : 0,
        font,
        numberFont,
        ...Array.from({ length: nameLines }, () => font),
        price ? font : 0,
    ].filter(Boolean);
    const textHeight = lineHeights.reduce((sum, size) => sum + size * 1.2, 0);
    const available = Math.max(3, h - 2 * pad - textHeight - 0.8);
    const barHeight = Math.max(3, available * (BAR_SHARE[options.bars] || BAR_SHARE.normal));

    return `<div class="lbl" style="width:${w}mm;height:${h}mm;padding:${pad}mm ${quiet / 2}mm;${options.border ? 'border:0.2mm solid #000;' : ''}">
        ${companyName ? `<div class="lbl__company" style="font-size:${companyFont}mm">${esc(companyName)}</div>` : ''}
        <div class="lbl__sr" style="font-size:${font}mm">${esc(stone.stone_code)}</div>
        <div class="lbl__bars" style="height:${barHeight}mm;width:calc(100% - ${quiet}mm)">${stone.barcode_svg}</div>
        <div class="lbl__code" style="font-size:${numberFont}mm;font-weight:700">${esc(stone.barcode)}</div>
        ${name ? `<div class="lbl__name" style="font-size:${font}mm">${esc(name)}</div>` : ''}
        ${price ? `<div class="lbl__meta" style="font-size:${font}mm">${esc(price)}</div>` : ''}
    </div>`;
}

/**
 * A QR label: company, item code, the QR filling the room left, then the name.
 *
 * @param {object} stone
 * @param {object} stock
 * @param {object} options
 * @returns {string}
 */
function qrLabelHtml(stone, stock, options) {
    const { w, h } = stock;
    const pad = clamp(Math.min(w, h) * 0.06, 0.6, 2);
    const scale = TEXT_SCALE[options.text] || 1;
    const font = clamp(h * 0.085, 1.5, 3.2) * scale;
    const companyFont = font * 1.1;
    const quiet = clamp(w * 0.06, 1.5, 5);
    const name = options.showName ? String(stone.name ?? '') : '';
    const perLine = Math.max(6, Math.floor((w - quiet) / (font * 0.58)));
    const nameLines = name ? Math.min(2, Math.ceil(name.length / perLine)) : 0;

    const textHeight = [companyName ? companyFont : 0, font, ...Array.from({ length: nameLines }, () => font)]
        .filter(Boolean).reduce((sum, size) => sum + size * 1.2, 0);
    const side = Math.max(4, Math.min(w - quiet, h - 2 * pad - textHeight - 0.8));

    return `<div class="lbl" style="width:${w}mm;height:${h}mm;padding:${pad}mm ${quiet / 2}mm;${options.border ? 'border:0.2mm solid #000;' : ''}">
        ${companyName ? `<div class="lbl__company" style="font-size:${companyFont}mm">${esc(companyName)}</div>` : ''}
        <div class="lbl__sr" style="font-size:${font}mm">${esc(stone.stone_code)}</div>
        ${stone.qr_uri ? `<img class="lbl__qr" src="${esc(stone.qr_uri)}" alt="" style="width:${side}mm;height:${side}mm">` : ''}
        ${name ? `<div class="lbl__name" style="font-size:${font}mm">${esc(name)}</div>` : ''}
    </div>`;
}

/**
 * One printed label carrying several barcodes side by side.
 *
 * Each barcode gets an equal slice of the width; a missing last one stays empty.
 *
 * @param {object[]} group
 * @param {object} stock
 * @param {object} options
 * @param {number} per
 * @returns {string}
 */
function pairHtml(group, stock, options, per) {
    const part = { ...stock, w: stock.w / per };

    return `<div class="lbl-pair" style="width:${stock.w}mm;height:${stock.h}mm">${Array.from({ length: per }, (_, i) => (group[i] ? labelHtml(group[i], part, options) : `<div style="width:${part.w}mm;height:${stock.h}mm"></div>`)).join('')}</div>`;
}

/**
 * Every label to print, in order: each stone repeated `copies` times.
 *
 * @param {object[]} stones
 * @param {number} copies
 * @returns {object[]}
 */
function expandCopies(stones, copies) {
    const kinds = labelKind === 'both' ? ['barcode', 'qr'] : [labelKind];

    return stones.flatMap((stone) => kinds.flatMap((kind) => Array.from({ length: copies }, () => ({ ...stone, _kind: kind }))));
}

/**
 * The pages for a set of labels.
 *
 * Sheets fill a fixed grid on A4 and carry on to the next page; a roll prints
 * one label on each page.
 *
 * @param {object[]} labels Stones, one entry per printed label
 * @param {object} stock
 * @param {object} options
 * @param {number} [maxPages] Stop after this many pages (the preview needs one)
 * @returns {{pages: string[], perPage: number, total: number, pageCount: number, sheet: boolean}}
 */
function buildPages(labels, stock, options, maxPages = Infinity) {
    const total = labels.length;
    const per = stock.split || 1;

    // One printed label holds `per` stones side by side (50 x 30 -> two 25 x 30).
    const cells = per === 1 ? labels : Array.from({ length: Math.ceil(total / per) }, (_, i) => labels.slice(i * per, i * per + per));
    const cellHtml = (cell) => (per === 1 ? labelHtml(cell, stock, options) : pairHtml(cell, stock, options, per));

    if (stock.kind === 'roll') {
        const pages = cells.slice(0, maxPages).map((cell) => `<section class="pg pg--roll" style="width:${stock.w}mm;height:${stock.h}mm">${cellHtml(cell)}</section>`);

        return { pages, perPage: per, total, pageCount: cells.length, sheet: false };
    }

    const perPage = stock.cols * stock.rows;
    const gridW = stock.cols * stock.w + (stock.cols - 1) * stock.gapX;
    const gridH = stock.rows * stock.h + (stock.rows - 1) * stock.gapY;
    const left = Math.max(0, (210 - gridW) / 2);
    const top = Math.max(0, (297 - gridH) / 2);
    const pageCount = Math.ceil(cells.length / perPage);
    const pages = [];

    for (let page = 0; page < Math.min(pageCount, maxPages); page += 1) {
        const html = cells.slice(page * perPage, (page + 1) * perPage).map(cellHtml).join('');
        pages.push(`<section class="pg pg--sheet" style="width:210mm;height:297mm;padding:${top}mm ${left}mm;grid-template-columns:repeat(${stock.cols},${stock.w}mm);grid-auto-rows:${stock.h}mm;column-gap:${stock.gapX}mm;row-gap:${stock.gapY}mm">${html}</section>`);
    }

    return { pages, perPage: perPage * per, total, pageCount, sheet: true };
}

/**
 * Styles shared by the preview and the printed frame.
 *
 * @returns {string}
 */
function labelCss() {
    return `
        .pg { box-sizing: border-box; background: #fff; overflow: hidden; }
        .pg--sheet { display: grid; align-content: start; justify-content: start; }
        .lbl__qr { display: block; flex: none; margin: 0.4mm 0; image-rendering: pixelated; }
        .lbl-pair { display: flex; align-items: stretch; overflow: hidden; }
        .pg--roll { display: flex; align-items: center; justify-content: center; }
        .lbl { box-sizing: border-box; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; text-align: center; line-height: 1.25; }
        .lbl__company { max-width: 100%; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lbl__sr { max-width: 100%; font-weight: 700; white-space: nowrap; }
        .lbl__name { max-width: 100%; font-weight: 700; line-height: 1.15; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow-wrap: anywhere; }
        .lbl__bars { flex: none; margin: 0.4mm 0; }
        .lbl__bars svg { display: block; width: 100%; height: 100%; }
        .lbl__code { letter-spacing: 0.08em; font-family: 'Courier New', monospace; white-space: nowrap; }
        .lbl__meta { max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600; }
    `;
}

/**
 * A full printable document for the chosen stock.
 *
 * @param {{pages: string[]}} built
 * @param {object} stock
 * @returns {string}
 */
function printDocument(built, stock) {
    const pageSize = stock.kind === 'roll' ? `${stock.w}mm ${stock.h}mm` : 'A4 portrait';

    return `<!DOCTYPE html><html lang="${document.documentElement.lang || 'es'}" translate="no"><head><meta charset="utf-8"><meta name="google" content="notranslate"><title>${t('Stone labels')}</title><style>
        @page { size: ${pageSize}; margin: 0; }
        html, body { margin: 0; padding: 0; background: #fff; }
        .pg { break-after: page; page-break-after: always; }
        .pg:last-child { break-after: auto; page-break-after: auto; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        ${labelCss()}
    </style></head><body>${built.pages.join('')}</body></html>`;
}

/**
 * Print a document through a hidden frame, then remove the frame.
 *
 * @param {string} html
 */
function printThroughFrame(html) {
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    document.body.appendChild(frame);

    const doc = frame.contentDocument;
    doc.open();
    doc.write(html);
    doc.close();

    const cleanup = () => window.setTimeout(() => frame.remove(), 1000);
    frame.contentWindow.addEventListener('afterprint', cleanup);

    // Give the frame a moment to lay out the vectors before printing.
    window.setTimeout(() => {
        frame.contentWindow.focus();
        frame.contentWindow.print();
        // Some browsers never fire afterprint for a hidden frame.
        window.setTimeout(() => frame.remove(), 120000);
    }, 250);
}

/**
 * CSRF token from the layout meta tag.
 *
 * @returns {string}
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/**
 * Wire the dialog and every control that opens it.
 */
export function bindStoneLabels() {
    const modal = document.getElementById('label-modal');
    if (!modal) {
        return;
    }

    const field = (name) => modal.querySelector(`[data-label-field="${name}"]`);
    const preview = modal.querySelector('[data-label-preview]');
    const summary = modal.querySelector('[data-label-summary]');
    const notice = modal.querySelector('[data-label-notice]');
    const printBtn = modal.querySelector('[data-label-print]');
    const customRow = modal.querySelector('[data-label-custom]');
    const dataUrl = modal.dataset.dataUrl;
    companyName = modal.dataset.company || '';

    let options = loadOptions();
    /** @type {object[]} */
    let stones = [];

    // Label stock list, built from the presets so there is one source of truth.
    const stockSelect = field('preset');
    stockSelect.innerHTML = PRESETS.map((group) => `<optgroup label="${esc(group.group)}">${group.items.map((item) => `<option value="${item.id}">${esc(item.name)}</option>`).join('')}</optgroup>`).join('');

    /**
     * Put the stored choices into the controls.
     */
    function applyOptionsToControls() {
        field('preset').value = findPreset(options.preset) ? options.preset : DEFAULTS.preset;
        field('text').value = options.text;
        field('bars').value = options.bars;
        field('copies').value = options.copies;
        field('showPrice').checked = Boolean(options.showPrice);
        field('showName').checked = Boolean(options.showName);
        field('border').checked = Boolean(options.border);
        field('customW').value = options.customW;
        field('customH').value = options.customH;
    }

    /**
     * Read the controls back into the options.
     */
    function readControls() {
        options = {
            preset: field('preset').value,
            text: field('text').value,
            bars: field('bars').value,
            copies: clamp(parseInt(field('copies').value, 10) || 1, 1, 500),
            showPrice: field('showPrice').checked,
            showName: field('showName').checked,
            border: field('border').checked,
            customW: Number(field('customW').value) || DEFAULTS.customW,
            customH: Number(field('customH').value) || DEFAULTS.customH,
        };
        saveOptions(options);
    }

    /**
     * Redraw the preview and the summary line from the current choices.
     */
    function render() {
        readControls();
        const stock = stockFor(options);
        customRow.classList.toggle('is-hidden', !stock.custom);

        if (!stones.length) {
            preview.innerHTML = '';
            summary.textContent = t('Nothing to print.');
            printBtn.disabled = true;

            return;
        }

        const labels = expandCopies(stones, options.copies);
        const built = buildPages(labels, stock, options, 1);
        const mmToPx = 96 / 25.4;
        const stageWidth = (preview.clientWidth || 340) - 32;

        // The first label, zoomed so its text and bars can be checked.
        const labelPxW = stock.w * mmToPx;
        const labelPxH = stock.h * mmToPx;
        const zoom = Math.min((stageWidth * 0.92) / labelPxW, 230 / labelPxH, 6);
        const zoomed = `<div class="label-preview__label" style="width:${labelPxW * zoom}px;height:${labelPxH * zoom}px">
            <div style="width:${stock.w}mm;height:${stock.h}mm;transform:scale(${zoom});transform-origin:top left">${(stock.split || 1) > 1 ? pairHtml(labels.slice(0, stock.split), stock, options, stock.split) : labelHtml(labels[0], stock, options)}</div>
        </div>`;

        // A sheet also shows its first page, small, to see how the labels fall on it.
        let thumbnail = '';
        if (built.sheet) {
            const pageScale = 190 / (297 * mmToPx);
            thumbnail = `<div class="label-preview__page" style="width:${210 * mmToPx * pageScale}px;height:${297 * mmToPx * pageScale}px">
                <div style="width:210mm;height:297mm;transform:scale(${pageScale});transform-origin:top left">${built.pages[0]}</div>
            </div>`;
        }

        preview.innerHTML = `<style>${labelCss()}</style>${zoomed}${thumbnail}`;

        const perPage = built.sheet ? t(':perPage per page', { perPage: built.perPage }) : (built.perPage > 1 ? t(':count per label', { count: built.perPage }) : t('1 per label'));
        const labelCount = built.total === 1 ? t(':count label', { count: built.total }) : t(':count labels', { count: built.total });
        const pageCount = built.sheet ? ' · ' + (built.pageCount === 1 ? t(':count page', { count: built.pageCount }) : t(':count pages', { count: built.pageCount })) : '';
        summary.textContent = `${labelCount} · ${stock.name} · ${perPage}${pageCount} · ${t('barcodes print as sharp vectors')}`;
        printBtn.disabled = false;
    }

    /**
     * Fetch the chosen stones' label data and open the dialog.
     *
     * @param {string[]} uuids
     */
    async function open(uuids, kind = 'barcode') {
        labelKind = ['qr', 'both'].includes(kind) ? kind : 'barcode';
        if (!uuids.length) {
            window.AnnigemsAlert?.notifyError(t('Select at least one item to print.'));

            return;
        }

        let json = {};
        let ok = false;
        try {
            const response = await fetch(dataUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ uuids, kind: labelKind }),
            });
            ok = response.ok;
            json = await response.json().catch(() => ({}));
        } catch (error) {
            ok = false;
        }

        if (!ok) {
            window.AnnigemsAlert?.notifyError(json.message || t('The labels could not be prepared.'));

            return;
        }

        stones = json.data?.stones || [];
        const skipped = Number(json.data?.skipped || 0);

        if (!stones.length) {
            window.AnnigemsAlert?.notifyError(t('None of the selected items has a barcode, so there is nothing to print.'));

            return;
        }

        modal.querySelector('[data-label-count]').textContent = t(':count selected', { count: stones.length });
        notice.textContent = skipped > 0
            ? (skipped === 1
                ? t(':count selected item has no barcode and is left out.', { count: skipped })
                : t(':count selected items have no barcode and are left out.', { count: skipped }))
            : '';
        notice.classList.toggle('is-hidden', skipped === 0);

        applyOptionsToControls();
        window.AnnigemsModal?.openModal('label-modal');
        // The pane has its real width only once the dialog is showing.
        window.requestAnimationFrame(render);
    }

    // Any control change redraws the preview.
    modal.querySelectorAll('[data-label-field]').forEach((control) => {
        control.addEventListener(control.type === 'checkbox' || control.tagName === 'SELECT' ? 'change' : 'input', render);
    });

    printBtn.addEventListener('click', () => {
        readControls();
        const stock = stockFor(options);
        const built = buildPages(expandCopies(stones, options.copies), stock, options);
        printThroughFrame(printDocument(built, stock));
    });

    // Openers: one stone's own button, or the ticked rows.
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const single = target?.closest('[data-label-open]');
        if (single) {
            event.preventDefault();
            open([single.dataset.labelOpen], single.dataset.labelKind);

            return;
        }

        const selected = target?.closest('[data-label-selected]');
        if (selected) {
            event.preventDefault();
            const form = selected.closest('form') || document;
            open([...form.querySelectorAll('input[name="uuids[]"]:checked')].map((box) => box.value), selected.dataset.labelKind);
        }
    });
}
