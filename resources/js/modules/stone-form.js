/**
 * Stone registration form: pricing-mode field switching, live cost preview,
 * and the deactivate confirmation.
 *
 * The preview mirrors StonePricingService so the user sees the same number
 * the server will store, but the server always recalculates before saving.
 */

/**
 * Read a field as a number. Blank or non-numeric returns null so the
 * preview stays quiet instead of showing NaN.
 *
 * @param {HTMLElement} root
 * @param {string} name Input name attribute
 * @returns {number|null}
 */
function numberOf(root, name) {
    const raw = root.querySelector(`[name="${name}"]`)?.value;
    if (raw === undefined || raw === null || String(raw).trim() === '') {
        return null;
    }
    const value = Number(raw);

    // Three decimals are stored, so the preview works from the same figure.
    const rounded = round3(value);

    return Number.isFinite(rounded) && rounded > 0 ? rounded : null;
}

/**
 * Round to the three decimals every stone figure is stored at, matching
 * StonePricingService::SCALE.
 *
 * @param {number} value
 * @returns {number}
 */
function round3(value) {
    return Math.round((value + Number.EPSILON) * 1000) / 1000;
}

/**
 * Trim trailing zeros so 4.200 reads as 4.2 in the formula text.
 *
 * @param {number} value
 * @returns {string}
 */
function tidy(value) {
    return String(Number(value.toFixed(3)));
}

/**
 * Build the formula sentence and total for the selected pricing mode.
 *
 * Per carat prices one piece first (carat x rate), then multiplies that
 * piece price by the piece count — the two figures are reported separately.
 *
 * @param {HTMLElement} root
 * @returns {{formula: string|null, total: number|null}}
 */
function calculate(root) {
    const mode = root.querySelector('[data-pricing-mode]')?.value;
    const pieces = numberOf(root, 'pieces');

    if (mode === 'per_carat') {
        const carat = numberOf(root, 'carat');
        const rate = numberOf(root, 'price_per_carat');
        if (carat === null || rate === null || pieces === null) {
            return { formula: null, total: null };
        }
        const piecePrice = round3(carat * rate);
        const total = round3(piecePrice * pieces);

        return {
            formula: `${tidy(carat)} ct x ${tidy(rate)} = ${tidy(piecePrice)} per piece, x ${pieces} pcs = ${tidy(total)}`,
            total,
        };
    }

    if (mode === 'per_piece') {
        const rate = numberOf(root, 'price_per_piece');
        if (rate === null || pieces === null) {
            return { formula: null, total: null };
        }
        const total = round3(pieces * rate);

        return { formula: `${pieces} pcs x ${tidy(rate)} = ${tidy(total)}`, total };
    }

    if (mode === 'per_gram') {
        const grams = numberOf(root, 'weight_grams');
        const rate = numberOf(root, 'price_per_gram');
        if (grams === null || rate === null) {
            return { formula: null, total: null };
        }
        const total = round3(grams * rate);

        return { formula: `${tidy(grams)} g x ${tidy(rate)} = ${tidy(total)}`, total };
    }

    return { formula: null, total: null };
}

/**
 * Paint the read-only calculation strip under the form.
 *
 * @param {HTMLElement} root
 */
function renderCalculation(root) {
    const formulaEl = root.querySelector('[data-calc-formula]');
    const totalEl = root.querySelector('[data-calc-total]');
    if (!formulaEl || !totalEl) {
        return;
    }

    const { formula, total } = calculate(root);
    formulaEl.textContent = formula || 'Fill in the priced fields to see the calculation.';
    totalEl.textContent = total === null ? '—' : tidy(total);
}

/**
 * Show only the inputs the selected mode needs, and disable the hidden ones
 * so the browser never posts a leftover value from another mode.
 *
 * @param {HTMLElement} root
 */
function applyMode(root) {
    const mode = root.querySelector('[data-pricing-mode]')?.value;

    root.querySelectorAll('[data-mode-fields]').forEach((group) => {
        const active = group.dataset.modeFields === mode;
        group.classList.toggle('is-hidden', !active);
        group.querySelectorAll('input, select').forEach((field) => {
            field.disabled = !active;
        });
    });

    // A gram lot is bulk weight; it holds no countable pieces.
    const piecesGroup = root.querySelector('[data-pieces-field]');
    if (piecesGroup) {
        const needsPieces = mode !== 'per_gram';
        piecesGroup.classList.toggle('is-hidden', !needsPieces);
        piecesGroup.querySelectorAll('input').forEach((field) => {
            field.disabled = !needsPieces;
        });
    }

    renderCalculation(root);
}

/**
 * Bind one registration/edit form root.
 *
 * @param {HTMLElement} root
 */
function bindStoneForm(root) {
    root.querySelector('[data-pricing-mode]')?.addEventListener('change', () => applyMode(root));

    root.querySelectorAll('[data-calc-input] input, input[data-calc-input]').forEach((field) => {
        field.addEventListener('input', () => renderCalculation(root));
    });

    applyMode(root);
}

/**
 * Submit a confirmed form, showing its button as busy while the page posts.
 *
 * Confirming forms are skipped by the generic loading helper, so the busy
 * state starts here, only after the operator has said yes.
 *
 * @param {HTMLFormElement} form
 */
function submitConfirmed(form) {
    const submitter = form.querySelector('[type="submit"]');
    if (submitter instanceof HTMLButtonElement && submitter.hasAttribute('data-icon-only')) {
        // An icon button has no room for a label; just stop a second click.
        submitter.disabled = true;
    } else if (submitter instanceof HTMLButtonElement) {
        window.AnnigemsButton?.startButtonLoading(submitter, submitter.dataset.loadingText || 'Saving...');
    }
    form.submit();
}

/**
 * Confirm before deleting a stone, saying what stock goes with it.
 */
function bindDeleteConfirms() {
    document.querySelectorAll('[data-confirm-delete-stone]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const remaining = form.dataset.remaining;
            const left = remaining && remaining !== '0 pcs' && remaining !== '0 g';
            const ok = await window.AnnigemsAlert?.confirmAction({
                title: 'Delete this stone?',
                text: left
                    ? `${remaining} still in stock will be written off. Invoices and history keep their record.`
                    : 'It has no stock left. Invoices and history keep their record.',
                confirmButtonText: 'Delete',
            });
            if (ok) {
                submitConfirmed(form);
            }
        });
    });
}

/**
 * Confirm before deleting a whole folder, saying how many lots go with it.
 */
function bindFolderDeleteConfirms() {
    document.querySelectorAll('[data-confirm-delete-folder]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const lots = Number(form.dataset.lotCount || 0);
            const ok = await window.AnnigemsAlert?.confirmAction({
                title: `Delete folder ${form.dataset.folderName}?`,
                text: lots > 0
                    ? `All ${lots} ${lots === 1 ? 'lot' : 'lots'} inside will be deleted and their remaining stock written off. Invoices and history keep their record.`
                    : 'This folder has no lots.',
                confirmButtonText: 'Delete folder',
            });
            if (ok) {
                submitConfirmed(form);
            }
        });
    });
}

/**
 * Confirm before deactivating a stone.
 */
function bindDeactivateConfirms() {
    document.querySelectorAll('[data-confirm-deactivate]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const ok = await window.AnnigemsAlert?.confirmAction({
                title: 'Deactivate this stone?',
                text: 'It cannot be added on Stone Intake until reactivated. Existing quantity is unchanged.',
                confirmButtonText: 'Deactivate',
            });
            if (ok) {
                submitConfirmed(form);
            }
        });
    });
}

/**
 * Page boot for stone forms.
 */
export function bindStoneForms() {
    document.querySelectorAll('[data-stone-form]').forEach((root) => bindStoneForm(root));
    bindDeactivateConfirms();
    bindDeleteConfirms();
    bindFolderDeleteConfirms();
}
