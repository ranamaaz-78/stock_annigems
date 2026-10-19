/**
 * Metals / purities tabbed CRUD.
 */

import { t } from './i18n';

/**
 * CSRF token from the layout meta tag.
 *
 * @returns {string}
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/**
 * Show or hide a modal.
 *
 * @param {HTMLElement} modal
 * @param {boolean} open
 */
function setModalOpen(modal, open) {
    modal.classList.toggle('hidden', !open);
    if (open) {
        modal.querySelector('input, select')?.focus();
    }
}

/**
 * POST/PUT/DELETE with the project JSON envelope.
 *
 * @param {string} url
 * @param {string} method
 * @param {FormData|null} body
 * @returns {Promise<{ok: boolean, json: object}>}
 */
async function send(url, method, body = null) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body,
    });
    const json = await response.json().catch(() => ({}));

    return { ok: response.ok, json };
}

/**
 * Bind tabs + modal CRUD on the metals workspace.
 */
export function bindMetalPurities() {
    const root = document.querySelector('[data-metal-purities]');
    if (!root) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'purities') {
        showTab('purities');
    }

    root.querySelectorAll('[data-tab]').forEach((button) => {
        button.addEventListener('click', () => showTab(button.dataset.tab));
    });

    const metalModal = document.getElementById('metal-modal');
    const purityModal = document.getElementById('purity-modal');
    const metalForm = document.getElementById('metal-form');
    const purityForm = document.getElementById('purity-form');

    /**
     * Switch metals / purities panels.
     *
     * @param {string} key
     */
    function showTab(key) {
        root.querySelectorAll('[data-tab]').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.tab === key);
        });
        root.querySelectorAll('[data-tab-panel]').forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.tabPanel !== key);
        });
    }

    root.querySelector('[data-add-metal]')?.addEventListener('click', () => {
        fillMetal({ active: '1', sort: '0' });
        setModalOpen(metalModal, true);
    });

    root.querySelectorAll('[data-edit-metal]').forEach((button) => {
        button.addEventListener('click', () => {
            fillMetal({
                uuid: button.dataset.uuid,
                name: button.dataset.name,
                active: button.dataset.active,
                sort: button.dataset.sort,
            });
            setModalOpen(metalModal, true);
        });
    });

    root.querySelectorAll('[data-delete-metal]').forEach((button) => {
        button.addEventListener('click', () => destroyRow(
            `/branch/metals/${button.dataset.uuid}`,
            button.dataset.name,
            button.dataset.items
        ));
    });

    root.querySelector('[data-add-purity]')?.addEventListener('click', () => {
        fillPurity({ active: '1', sort: '0' });
        setModalOpen(purityModal, true);
    });

    root.querySelectorAll('[data-edit-purity]').forEach((button) => {
        button.addEventListener('click', () => {
            fillPurity({
                uuid: button.dataset.uuid,
                name: button.dataset.name,
                value: button.dataset.value,
                metal: button.dataset.metal,
                active: button.dataset.active,
                sort: button.dataset.sort,
            });
            setModalOpen(purityModal, true);
        });
    });

    root.querySelectorAll('[data-delete-purity]').forEach((button) => {
        button.addEventListener('click', () => destroyRow(
            `/branch/metals/purities/${button.dataset.uuid}`,
            button.dataset.name,
            button.dataset.items
        ));
    });

    [metalModal, purityModal].forEach((modal) => {
        modal?.querySelectorAll('[data-modal-close], [data-modal-overlay]').forEach((el) => {
            el.addEventListener('click', () => setModalOpen(modal, false));
        });
    });

    metalForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitCatalog(metalForm, metalForm.dataset.uuid ? `/branch/metals/${metalForm.dataset.uuid}` : '/branch/metals');
    });

    purityForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitCatalog(
            purityForm,
            purityForm.dataset.uuid ? `/branch/metals/purities/${purityForm.dataset.uuid}` : '/branch/metals/purities'
        );
    });

    /**
     * @param {object} values
     */
    function fillMetal(values) {
        metalForm.reset();
        metalForm.querySelector('[name="name"]').value = values.name || '';
        metalForm.querySelector('[name="is_active"]').value = values.active || '1';
        metalForm.querySelector('[name="sort_order"]').value = values.sort || '0';
        metalForm.dataset.uuid = values.uuid || '';
        metalForm.querySelector('input[name="_method"]').value = values.uuid ? 'PUT' : 'POST';
        const title = metalModal.querySelector('h2');
        if (title) {
            title.textContent = values.uuid ? t('Edit metal') : t('Add metal');
        }
    }

    /**
     * @param {object} values
     */
    function fillPurity(values) {
        purityForm.reset();
        purityForm.querySelector('[name="name"]').value = values.name || '';
        purityForm.querySelector('[name="value"]').value = values.value || '';
        purityForm.querySelector('[name="metal_type_id"]').value = values.metal || '';
        purityForm.querySelector('[name="is_active"]').value = values.active || '1';
        purityForm.querySelector('[name="sort_order"]').value = values.sort || '0';
        purityForm.dataset.uuid = values.uuid || '';
        purityForm.querySelector('input[name="_method"]').value = values.uuid ? 'PUT' : 'POST';
        const title = purityModal.querySelector('h2');
        if (title) {
            title.textContent = values.uuid ? t('Edit purity') : t('Add purity');
        }
    }
}

/**
 * Save metal or purity; reload on success.
 *
 * @param {HTMLFormElement} form
 * @param {string} url
 */
async function submitCatalog(form, url) {
    if (window.AnnigemsForm && !window.AnnigemsForm.validateForm(form)) {
        return;
    }
    const submitter = form.querySelector('[type="submit"]');
    const loader = window.AnnigemsButton?.startButtonLoading(submitter, 'Saving...');
    const { ok, json } = await send(url, 'POST', new FormData(form));
    if (!ok) {
        window.AnnigemsForm?.applyServerErrors(form, json.errors || {});
        window.AnnigemsAlert?.notifyError(json.message || t('Could not save.'));
        loader?.restore();
        return;
    }
    window.location.reload();
}

/**
 * Confirm then DELETE a catalog row.
 *
 * @param {string} url
 * @param {string} name
 * @param {string} items
 */
async function destroyRow(url, name, items) {
    const ok = await window.AnnigemsAlert?.confirmAction({
        title: t('Delete :name?', { name: name }),
        text: items === '0'
            ? t('This value will be removed if nothing else depends on it.')
            : t(':items jewellery item(s) use this value. Delete will be blocked — deactivate instead.', { items: items }),
        confirmButtonText: 'Delete',
    });
    if (!ok) {
        return;
    }
    const body = new FormData();
    body.append('_method', 'DELETE');
    const { ok: saved, json } = await send(url, 'POST', body);
    if (!saved) {
        window.AnnigemsAlert?.notifyError(json.message || t('Could not delete this value.'));
        return;
    }
    window.location.reload();
}
