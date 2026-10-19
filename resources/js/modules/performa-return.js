/**
 * Performa unpaid return: selected lines or every outstanding qty.
 */

import { t } from './i18n';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/**
 * Bind the return modal on the performa detail screen.
 */
export function bindPerformaReturn() {
    const root = document.getElementById('performa-return-root');
    if (!root) return;

    let config = {};
    try {
        config = JSON.parse(root.dataset.config || '{}');
    } catch (error) {
        console.error('performa-return: could not parse data-config', error);
        return;
    }

    const routes = config.routes || {};
    const form = document.getElementById('performa-return-form');
    if (!form) return;

    /**
     * Outstanding lines the operator ticked or typed a qty for.
     */
    function selectedLines() {
        return [...form.querySelectorAll('[data-return-line]')].flatMap((row) => {
            const id = parseInt(row.dataset.lineId, 10);
            // A weighed stone is returned in grams (decimals); pieces come whole.
            const weighed = row.dataset.weighed === '1';
            const outstanding = weighed
                ? (parseFloat(row.dataset.outstanding) || 0)
                : (parseInt(row.dataset.outstanding, 10) || 0);
            const isJewellery = row.dataset.lineType === 'jewellery_item';

            if (isJewellery) {
                if (!row.querySelector('[data-return-select]')?.checked) {
                    return [];
                }
                return [{ performa_item_id: id, quantity: 1 }];
            }

            const typed = row.querySelector('[data-return-qty]')?.value;
            const qty = weighed
                ? Math.max(0, Math.round((parseFloat(typed) || 0) * 1000) / 1000)
                : Math.max(0, parseInt(typed, 10) || 0);
            if (qty <= 0) {
                return [];
            }

            return [{ performa_item_id: id, quantity: Math.min(qty, outstanding) }];
        });
    }

    /**
     * POST return_all or selected lines, then reload the performa.
     *
     * @param {object} payload
     * @param {HTMLElement} button
     */
    async function submitReturn(payload, button) {
        const loading = window.AnnigemsButton?.startButtonLoading(button, 'Returning...');
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        };

        const response = await fetch(routes.return, {
            method: 'POST',
            headers,
            body: JSON.stringify(payload),
        });
        const json = await response.json().catch(() => ({}));
        loading?.restore();

        if (response.redirected) {
            window.location.href = response.url;
            return;
        }

        if (response.ok) {
            window.AnnigemsAlert?.notifySuccess(json.message || t('Unpaid goods returned to stock.'));
            window.location.href = routes.show || window.location.href;
            return;
        }

        const messages = Object.values(json?.errors || {}).flat().filter(Boolean);
        window.AnnigemsAlert?.notifyError(messages.join(' ') || json.message || t('The return could not be saved.'));
    }

    form.querySelector('[data-return-selected]')?.addEventListener('click', async () => {
        const lines = selectedLines();
        if (!lines.length) {
            window.AnnigemsAlert?.notifyError(t('Select a piece or enter a stone quantity to return.'));
            return;
        }

        const ok = await window.AnnigemsAlert?.confirmAction({
            title: t('Return selected goods?'),
            text: t('Only the pieces and stone qty you chose go back to stock. Settled lines stay sold.'),
            confirmButtonText: t('Return selected'),
        });
        if (!ok) return;

        await submitReturn({ lines }, form.querySelector('[data-return-selected]'));
    });

    form.querySelector('[data-return-all]')?.addEventListener('click', async () => {
        const ok = await window.AnnigemsAlert?.confirmAction({
            title: t('Return all unpaid goods?'),
            text: t('Every unpaid jewellery piece and leftover stone qty goes back to stock. Settled lines stay sold.'),
            confirmButtonText: t('Return all unpaid'),
        });
        if (!ok) return;

        await submitReturn({ return_all: true }, form.querySelector('[data-return-all]'));
    });
}
