/**
 * Select2 searchable dropdowns.
 *
 * Native <select class="input-field"> controls cannot be typed into, which
 * becomes unusable once a list grows (customers, stones, items, staff,
 * categories). This module upgrades every matching select to Select2 so
 * staff can search by name or code without changing Blade markup.
 *
 * Opt out on a single field with data-no-select2. After JS replaces
 * <option> nodes, call refreshSelect() so the widget re-reads the list.
 *
 * Remote lists (data-ajax-url) do not use Select2's ajax adapter. Select2
 * 4.1.0-rc maps results with AjaxAdapter.prototype._normalizeItem, which is
 * undefined, so a successful JSON response still shows "The results could
 * not be loaded." Query is implemented on the widget's dataAdapter instead.
 */

import $ from 'jquery';
import select2 from 'select2';

// Select2's CJS build is a factory that attaches $.fn.select2. Vite ESM
// must hand it the same jQuery instance we imported.
window.jQuery = window.$ = $;

if (typeof select2 === 'function' && typeof $.fn.select2 !== 'function') {
    select2(window, $);
}

/**
 * Whether this select should be upgraded.
 *
 * @param {Element} select
 * @returns {boolean}
 */
function shouldEnhance(select) {
    if (!(select instanceof HTMLSelectElement)) {
        return false;
    }
    if (select.dataset.noSelect2 === '1' || select.hasAttribute('data-no-select2')) {
        return false;
    }
    if (select.closest('.select2')) {
        return false;
    }

    return select.classList.contains('input-field') || select.hasAttribute('data-select-search');
}

/**
 * Placeholder text from the empty option, matching existing Blade copy.
 *
 * @param {HTMLSelectElement} select
 * @returns {string}
 */
function placeholderOf(select) {
    const empty = [...select.options].find((option) => option.value === '');

    return empty?.textContent?.trim() || 'Select…';
}

/**
 * Coerce a JSON list. Eloquent collections encode as objects when keys are ids.
 *
 * @param {unknown} value
 * @returns {Array<object>}
 */
function asList(value) {
    if (Array.isArray(value)) {
        return value;
    }
    if (value && typeof value === 'object') {
        return Object.values(value);
    }

    return [];
}

/**
 * One-line label for a remote row. Stones show code + short code + stock;
 * customers keep name · phone · customer_code.
 *
 * @param {object} row
 * @param {string} collection
 * @returns {string}
 */
function rowLabel(row, collection) {
    if (collection === 'stones') {
        return [
            row.stone_code,
            row.short_code,
            row.name,
            row.quantity != null && row.quantity !== '' ? `(${row.quantity})` : null,
        ].filter(Boolean).join(' · ');
    }

    return [row.name, row.phone, row.customer_code || row.creditor_code || row.code].filter(Boolean).join(' · ');
}

/**
 * Map the project JSON envelope into Select2's { results: [{ id, text }] }.
 *
 * Stone rows keep the full payload on `stone` so the jewellery form can
 * add the packet without a second lookup. Sales `items` keep `line` so
 * performa/quotation can add jewellery or a stone in one pick.
 *
 * @param {object} json
 * @param {string} collection
 * @returns {{results: Array<{id: string, text: string, stone?: object}>}}
 */
function mapAjaxResults(json, collection) {
    const rows = asList(json?.data?.[collection]);

    return {
        results: rows.map((row) => ({
            id: String(row.uuid ?? row.id ?? ''),
            text: collection === 'items' ? (row.label || row.name || '') : rowLabel(row, collection),
            stone: collection === 'stones' ? row : undefined,
            line: collection === 'items' ? row.line : undefined,
        })),
    };
}

/**
 * Replace Select2's local query with a fetch against data-ajax-url.
 *
 * @param {HTMLSelectElement} select
 */
function bindRemoteQuery(select) {
    const url = select.dataset.ajaxUrl;
    if (!url) {
        return;
    }

    const $el = $(select);
    const inst = $el.data('select2');
    if (!inst?.dataAdapter) {
        return;
    }

    const collection = select.dataset.ajaxCollection || 'results';
    let requestId = 0;
    /** @type {AbortController|null} */
    let controller = null;
    let timer = null;

    inst.dataAdapter.query = function queryRemote(params, callback) {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => {
            const id = ++requestId;
            controller?.abort();
            controller = new AbortController();

            const target = new URL(url, window.location.origin);
            target.searchParams.set('q', params.term || '');

            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            fetch(target.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.json();
                })
                .then((json) => {
                    if (id !== requestId) {
                        return;
                    }
                    callback(mapAjaxResults(json, collection));
                })
                .catch((error) => {
                    if (error?.name === 'AbortError' || id !== requestId) {
                        return;
                    }
                    callback({ results: [] });
                });
        }, 250);
    };
}

/**
 * Select2 options for one native select.
 *
 * @param {HTMLSelectElement} select
 * @returns {object}
 */
function select2Options(select) {
    const hasEmpty = [...select.options].some((option) => option.value === '');
    const modal = select.closest('[role="dialog"]');

    return {
        width: '100%',
        minimumResultsForSearch: 0,
        allowClear: hasEmpty && !select.required,
        placeholder: placeholderOf(select),
        dropdownParent: modal ? $(modal) : $(document.body),
        language: {
            noResults: () => 'No matches',
            searching: () => 'Searching…',
        },
    };
}

/**
 * Mount Select2 on one native select.
 *
 * @param {HTMLSelectElement} select
 */
export function enhanceSelect(select) {
    if (!shouldEnhance(select) || typeof $.fn.select2 !== 'function') {
        return;
    }

    const $el = $(select);
    if ($el.hasClass('select2-hidden-accessible')) {
        bindRemoteQuery(select);
        bindSelect2Ui(select);
        return;
    }

    $el.select2(select2Options(select));
    bindRemoteQuery(select);
    bindSelect2Ui(select);
}

/**
 * Select2 writes the value with jQuery trigger('change'). That notifies
 * jQuery listeners and an onchange property, not addEventListener('change').
 * Cascade loaders (category → sub-category, metal → purity, stone type →
 * colour/clarity) all use native listeners, so a pick never fetched options
 * and the child widget showed "No matches". Re-dispatch a real Event.
 *
 * @param {HTMLSelectElement} select
 */
function bridgeNativeChange(select) {
    $(select)
        .off('.annigemsChange')
        .on(
            'select2:select.annigemsChange select2:clear.annigemsChange select2:unselect.annigemsChange',
            () => {
                select.dispatchEvent(new Event('change', { bubbles: true }));
            },
        );
}

/**
 * Widget chrome that must be re-attached after destroy + enhance (refreshSelect).
 *
 * @param {HTMLSelectElement} select
 */
function bindSelect2Ui(select) {
    const $el = $(select);

    bridgeNativeChange(select);

    // Native listeners survive Select2 destroy(); bind them once per element.
    if (select.dataset.select2Listeners !== '1') {
        select.dataset.select2Listeners = '1';
        // Select2 hides the native control; HTML5 "required" would otherwise
        // fail with an unfocusable-field warning. Show our inline error instead.
        select.addEventListener('invalid', (event) => {
            event.preventDefault();
            window.AnnigemsForm?.validateField?.(select);
            $(select).select2('open');
        });
    }

    // Treat closing the widget like a blur so data-validate-form still runs.
    $el.off('select2:close.annigems').on('select2:close.annigems', () => {
        window.AnnigemsForm?.validateField?.(select);
    });
}

/**
 * Upgrade every eligible select inside a root (document or a new fragment).
 *
 * @param {ParentNode} [root]
 */
export function enhanceSelects(root = document) {
    root.querySelectorAll('select.input-field, select[data-select-search]').forEach((select) => {
        enhanceSelect(select);
    });
}

/**
 * Tear down Select2 widgets before the caller wipes their parent innerHTML.
 * Leaves the native <select> in place; leftover dropdowns on <body> are removed.
 *
 * @param {ParentNode} root
 */
export function destroySelects(root) {
    if (typeof $.fn.select2 !== 'function') {
        return;
    }

    root.querySelectorAll('select').forEach((select) => {
        const $el = $(select);
        if ($el.hasClass('select2-hidden-accessible')) {
            $el.select2('destroy');
        }
    });
}

/**
 * Re-read <option> nodes after cascading AJAX fills (category → sub-category,
 * metal → purity, stone type → colour/clarity).
 *
 * @param {HTMLSelectElement|null} select
 */
export function refreshSelect(select) {
    if (!(select instanceof HTMLSelectElement) || typeof $.fn.select2 !== 'function') {
        return;
    }

    const $el = $(select);
    if ($el.hasClass('select2-hidden-accessible')) {
        $el.select2('destroy');
    }
    enhanceSelect(select);
}

/**
 * Watch the document for selects inserted after first paint (quotation lines,
 * modal bodies rebuilt by AJAX).
 */
let selectObserver = null;

function observeNewSelects() {
    if (selectObserver) {
        return;
    }

    selectObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }
                if (node.matches('select.input-field, select[data-select-search]')) {
                    enhanceSelect(node);
                }
                node.querySelectorAll?.('select.input-field, select[data-select-search]').forEach((next) => {
                    enhanceSelect(next);
                });
            });
        });
    });

    // Observe <html> so a kiosk body-swap still sees new <select> nodes.
    selectObserver.observe(document.documentElement, { childList: true, subtree: true });
}

/**
 * Page boot: enhance current selects, then keep watching for new ones.
 */
export function bindSelectSearch() {
    enhanceSelects(document);
    observeNewSelects();
}

window.AnnigemsSelect = {
    enhanceSelect,
    enhanceSelects,
    destroySelects,
    refreshSelect,
};
