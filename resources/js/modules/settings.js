/**
 * Settings forms: per-group SweetAlert confirm, unsaved-dirty warning,
 * parent-toggle reveal, and the branch print-preview pane.
 *
 * Uses AnnigemsAlert.confirmAction — never window.alert() / confirm().
 */

import { confirmAction } from './alerts';

/**
 * Serialize a form so we can tell whether the operator changed anything.
 *
 * @param {HTMLFormElement} form
 * @returns {string}
 */
function snapshotForm(form) {
    const data = new FormData(form);
    const pairs = [];

    data.forEach((value, key) => {
        if (value instanceof File) {
            pairs.push(`${key}:${value.name}:${value.size}`);
            return;
        }
        pairs.push(`${key}:${String(value)}`);
    });

    return pairs.join('|');
}

/**
 * Show or hide rows that belong to a parent toggle (tax rate under tax_enabled).
 *
 * @param {HTMLFormElement} form
 */
function syncParentRows(form) {
    form.querySelectorAll('[data-settings-parent]').forEach((row) => {
        const parentKey = row.getAttribute('data-settings-parent');
        const toggle = form.querySelector(`[data-settings-toggle="${parentKey}"]`);
        const on = toggle ? toggle.checked : true;
        row.hidden = !on;
    });
}

/**
 * Bind one settings (or contact) form.
 *
 * @param {HTMLFormElement} form
 */
function bindForm(form) {
    let initial = snapshotForm(form);
    let allowLeave = false;

    syncParentRows(form);

    form.querySelectorAll('[data-settings-toggle]').forEach((toggle) => {
        toggle.addEventListener('change', () => syncParentRows(form));
    });

    window.addEventListener('beforeunload', (event) => {
        if (!form.isConnected || allowLeave || snapshotForm(form) === initial) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });

    const save = form.querySelector('[data-settings-save]');
    if (!save) {
        return;
    }

    /**
     * Confirm the named group, then submit. The save button is type=button
     * so the loading helper does not disable it before the dialog resolves.
     */
    save.addEventListener('click', async () => {
        const label = form.dataset.groupLabel || 'these settings';
        const ok = await confirmAction({
            title: `Save ${label}?`,
            text: `This updates ${label}. Existing invoices keep the tax and discount they already stored.`,
            confirmButtonText: `Save ${label}`,
            icon: 'question',
        });

        if (!ok) {
            return;
        }

        allowLeave = true;
        initial = snapshotForm(form);
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });
}

/**
 * Resize the preview tiles when the Admin changes label size or invoice format.
 */
function bindPrintPreview() {
    const root = document.querySelector('[data-print-preview]');
    if (!root) {
        return;
    }

    const label = root.querySelector('[data-preview-label]');
    const sheet = root.querySelector('[data-preview-sheet]');
    const sizeInput = document.querySelector('[name="label_size"]');
    const formatInput = document.querySelector('[name="invoice_print_format"]');

    /**
     * Swap the size modifier class on a preview tile.
     *
     * @param {HTMLElement|null} el
     * @param {string} prefix
     * @param {string} value
     */
    function applyModifier(el, prefix, value) {
        if (!el || !value) {
            return;
        }
        [...el.classList].filter((cls) => cls.startsWith(prefix)).forEach((cls) => el.classList.remove(cls));
        el.classList.add(`${prefix}${value}`);
    }

    sizeInput?.addEventListener('change', () => {
        applyModifier(label, 'settings-preview__label--', sizeInput.value);
    });
    formatInput?.addEventListener('change', () => {
        applyModifier(sheet, 'settings-preview__sheet--', formatInput.value);
    });
}

/**
 * Attach settings behaviour on pages that have a settings form or preview.
 */
export function bindSettingsForms() {
    document.querySelectorAll('[data-settings-form]').forEach((form) => {
        if (form instanceof HTMLFormElement) {
            bindForm(form);
        }
    });
    bindPrintPreview();
}
