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

/** Mobile-safe localStorage key for the last choices. */
const STORAGE_KEY = 'annigems.stones.labelOptions';

/**
 * Label stock choices. Sheets carry their grid (columns, rows, gaps); rolls
 * are one label per page.
 *
 * @type {Array<{group: string, items: Array<object>}>}
 */
const PRESETS = (() => {
    const roll = (w, h) => ({ id: `roll-${w}x${h}`, name: t('Roll label :w × :h mm', { w: w, h: h }), kind: 'roll', w, h });
    const rollSizes = [
        [20, 10], [20, 40], [22.5, 22.5], [25, 15], [25, 25], [30, 15], [30, 20], [30, 30], [30, 50],
        [35, 35], [38, 25], [40, 20], [40, 25], [40, 30], [40, 40], [45, 30], [50, 25], [50, 30],
        [50, 50], [60, 30], [60, 40], [70, 40], [75, 50], [100, 50], [100, 70], [100, 100],
    ];

    return [
        {
            group: 'A4 sheets',
            items: [
                { id: 'a4-24', name: 'A4 sheet, 24 labels (64 × 33.9 mm)', kind: 'sheet', w: 64, h: 33.9, cols: 3, rows: 8, gapX: 2.5, gapY: 0 },
                { id: 'a4-40', name: 'A4 sheet, 40 labels (48.5 × 25.4 mm)', kind: 'sheet', w: 48.5, h: 25.4, cols: 4, rows: 10, gapX: 0, gapY: 0 },
            ],
        },
        {
            group: t('Thermal roll'),
            items: [{ id: 'thermal-58', name: t('Thermal roll 58 mm'), kind: 'roll', w: 58, h: 30 }],
        },
        { group: t('Roll labels'), items: rollSizes.map(([w, h]) => roll(w, h)) },
        { group: t('Other'), items: [{ id: 'custom', name: t('Custom size…'), kind: 'roll', w: 50, h: 30, custom: true }] },
    ];
})();

/** Text size and barcode height choices. */
const TEXT_SCALE = { small: 0.85, normal: 1, large: 1.25 };
const BAR_SHARE = { short: 0.5, normal: 0.75, tall: 1 };

/** Defaults for a first visit. */
const DEFAULTS = {
    preset: 'a4-24',
    text: 'normal',
    bars: 'normal',
    copies: 1,
    showPrice: true,
    showName: true,
    showSr: true,
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
    const { w, h } = stock;
    const pad = clamp(Math.min(w, h) * 0.07, 0.8, 2.5);
    const scale = TEXT_SCALE[options.text] || 1;
    const nameFont = clamp(h * 0.1, 1.7, 3.6) * scale;
    const codeFont = clamp(h * 0.085, 1.5, 3.2) * scale;
    const metaFont = clamp(h * 0.08, 1.4, 3) * scale;

    const meta = [
        options.showSr ? stone.stone_code : '',
        options.showPrice ? stone.price : '',
    ].filter(Boolean);

    // Lines of text decide how much height is left for the bars.
    const lines = [
        options.showName ? nameFont : 0,
        codeFont,
        meta.length ? metaFont : 0,
    ].filter(Boolean);
    const textHeight = lines.reduce((sum, size) => sum + size * 1.3, 0);
    const gaps = lines.length * 0.4;
    const available = Math.max(3, h - 2 * pad - textHeight - gaps);
    const barHeight = Math.max(3, available * (BAR_SHARE[options.bars] || BAR_SHARE.normal));

    // Bars keep a quiet zone either side, which a scanner needs.
    const quiet = clamp(w * 0.06, 1.5, 5);

    return `<div class="lbl" style="width:${w}mm;height:${h}mm;padding:${pad}mm ${quiet / 2}mm;${options.border ? 'border:0.2mm solid #000;' : ''}">
        ${options.showName ? `<div class="lbl__name" style="font-size:${nameFont}mm">${esc(stone.name)}</div>` : ''}
        <div class="lbl__bars" style="height:${barHeight}mm;width:calc(100% - ${quiet}mm)">${stone.barcode_svg}</div>
        <div class="lbl__code" style="font-size:${codeFont}mm">${esc(stone.barcode)}</div>
        ${meta.length ? `<div class="lbl__meta" style="font-size:${metaFont}mm">${meta.map(esc).join(' &nbsp;·&nbsp; ')}</div>` : ''}
    </div>`;
}

/**
 * Every label to print, in order: each stone repeated `copies` times.
 *
 * @param {object[]} stones
 * @param {number} copies
 * @returns {object[]}
 */
function expandCopies(stones, copies) {
    return stones.flatMap((stone) => Array.from({ length: copies }, () => stone));
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

    if (stock.kind === 'roll') {
        const pages = labels.slice(0, maxPages).map((stone) => `<section class="pg pg--roll" style="width:${stock.w}mm;height:${stock.h}mm">${labelHtml(stone, stock, options)}</section>`);

        return { pages, perPage: 1, total, pageCount: total, sheet: false };
    }

    const perPage = stock.cols * stock.rows;
    const gridW = stock.cols * stock.w + (stock.cols - 1) * stock.gapX;
    const gridH = stock.rows * stock.h + (stock.rows - 1) * stock.gapY;
    const left = Math.max(0, (210 - gridW) / 2);
    const top = Math.max(0, (297 - gridH) / 2);
    const pageCount = Math.ceil(total / perPage);
    const pages = [];

    for (let page = 0; page < Math.min(pageCount, maxPages); page += 1) {
        const cells = labels.slice(page * perPage, (page + 1) * perPage).map((stone) => labelHtml(stone, stock, options)).join('');
        pages.push(`<section class="pg pg--sheet" style="width:210mm;height:297mm;padding:${top}mm ${left}mm;grid-template-columns:repeat(${stock.cols},${stock.w}mm);grid-auto-rows:${stock.h}mm;column-gap:${stock.gapX}mm;row-gap:${stock.gapY}mm">${cells}</section>`);
    }

    return { pages, perPage, total, pageCount, sheet: true };
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
        .pg--roll { display: flex; align-items: center; justify-content: center; }
        .lbl { box-sizing: border-box; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; text-align: center; line-height: 1.25; }
        .lbl__name { max-width: 100%; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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

    return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${t('Stone labels')}</title><style>
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
        field('showSr').checked = Boolean(options.showSr);
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
            showSr: field('showSr').checked,
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
            <div style="width:${stock.w}mm;height:${stock.h}mm;transform:scale(${zoom});transform-origin:top left">${labelHtml(stones[0], stock, options)}</div>
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

        const perPage = built.sheet ? t(':perPage per page', { perPage: built.perPage }) : t('1 per label');
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
    async function open(uuids) {
        if (!uuids.length) {
            window.AnnigemsAlert?.notifyError(t('Select at least one stone to print.'));

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
                body: JSON.stringify({ uuids }),
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
            window.AnnigemsAlert?.notifyError(t('None of the selected stones has a packet barcode, so there is nothing to print.'));

            return;
        }

        modal.querySelector('[data-label-count]').textContent = `${stones.length} ${stones.length === 1 ? 'stone' : 'stones'} selected`;
        notice.textContent = skipped > 0
            ? (skipped === 1
                ? t(':count selected stone has no packet barcode and is left out.', { count: skipped })
                : t(':count selected stones have no packet barcode and are left out.', { count: skipped }))
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
            open([single.dataset.labelOpen]);

            return;
        }

        const selected = target?.closest('[data-label-selected]');
        if (selected) {
            event.preventDefault();
            const form = selected.closest('form') || document;
            open([...form.querySelectorAll('input[name="uuids[]"]:checked')].map((box) => box.value));
        }
    });
}
