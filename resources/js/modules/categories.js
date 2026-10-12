/**
 * Category / sub-category split page: select parent, modal CRUD, in-use delete.
 */

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
 * JSON-friendly fetch with the project envelope.
 *
 * @param {string} url
 * @param {string} method
 * @param {FormData|null} body
 * @returns {Promise<{ok: boolean, status: number, json: object}>}
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

    return { ok: response.ok, status: response.status, json };
}

/**
 * Bind the split category workspace.
 */
export function bindCategories() {
    const root = document.querySelector('[data-categories]');
    if (!root) {
        return;
    }

    const canManage = root.dataset.canManage === '1';
    const categoryModal = document.getElementById('category-modal');
    const subModal = document.getElementById('sub-modal');
    const categoryForm = document.getElementById('category-form');
    const subForm = document.getElementById('sub-form');
    const subRows = root.querySelector('[data-sub-rows]');
    let selectedUuid = root.querySelector('[data-select-category]')?.dataset.uuid || '';

    /**
     * Load sub-categories for the selected parent.
     *
     * @param {string} uuid
     */
    async function loadSubs(uuid) {
        selectedUuid = uuid;
        root.querySelectorAll('[data-select-category]').forEach((button) => {
            button.classList.toggle('bg-surface-container-low', button.dataset.uuid === uuid);
        });
        const url = root.dataset.subsUrl.replace('__UUID__', uuid);
        const { ok, json } = await send(url, 'GET');
        if (!ok) {
            window.AnnigemsAlert?.notifyError(json.message || 'Could not load sub-categories.');
            return;
        }
        const category = json.data?.category || {};
        const nameEl = root.querySelector('[data-selected-name]');
        const usageEl = root.querySelector('[data-selected-usage]');
        if (nameEl) {
            nameEl.textContent = category.name || '';
        }
        if (usageEl) {
            usageEl.textContent = `Used by ${category.usage ?? 0} item(s)`;
        }
        renderSubs(json.data?.sub_categories || []);
    }

    /**
     * Render the right-hand table.
     *
     * @param {Array<object>} rows
     */
    function renderSubs(rows) {
        if (!subRows) {
            return;
        }
        if (rows.length === 0) {
            subRows.innerHTML = '<tr><td colspan="5" class="text-body-sm text-on-surface-variant">No sub-categories yet.</td></tr>';
            return;
        }
        subRows.innerHTML = rows.map((row) => `
            <tr data-sub-uuid="${row.uuid}" data-name="${escapeAttr(row.name)}" data-description="${escapeAttr(row.description || '')}" data-active="${row.is_active ? '1' : '0'}" data-sort="${row.sort_order}" data-items="${row.items_count}">
                <td>${escapeHtml(row.name)}</td>
                <td>${row.is_active ? 'Active' : 'Inactive'}</td>
                <td class="font-data-mono">${row.items_count}</td>
                <td class="font-data-mono">${row.sort_order}</td>
                ${canManage ? `<td>
                    <button type="button" class="form-link text-body-sm" data-edit-sub>Edit</button>
                    ·
                    <button type="button" class="form-link text-body-sm" data-delete-sub>Delete</button>
                </td>` : ''}
            </tr>
        `).join('');
    }

    root.querySelectorAll('[data-select-category]').forEach((button) => {
        button.addEventListener('click', () => loadSubs(button.dataset.uuid));
    });

    /**
     * Header Add sits in the page-header slot; still inside [data-categories]
     * after the wrapper, but query document so the control always binds.
     */
    document.querySelector('[data-add-category]')?.addEventListener('click', () => {
        fillCatalogForm(categoryForm, {});
        categoryForm.dataset.uuid = '';
        categoryForm.querySelector('input[name="_method"]').value = 'POST';
        if (categoryModal.querySelector('h2')) {
            categoryModal.querySelector('h2').textContent = 'Add category';
        }
        setModalOpen(categoryModal, true);
    });

    root.querySelector('[data-edit-category]')?.addEventListener('click', () => {
        const selected = root.querySelector('[data-select-category].bg-surface-container-low')
            || root.querySelector('[data-select-category]');
        if (!selected) {
            return;
        }
        fillCatalogForm(categoryForm, {
            name: selected.dataset.name,
            description: selected.dataset.description,
            active: selected.dataset.active,
            sort: selected.dataset.sort,
        });
        categoryForm.dataset.uuid = selected.dataset.uuid;
        categoryForm.querySelector('input[name="_method"]').value = 'PUT';
        if (categoryModal.querySelector('h2')) {
            categoryModal.querySelector('h2').textContent = 'Edit category';
        }
        setModalOpen(categoryModal, true);
    });

    root.querySelector('[data-delete-category]')?.addEventListener('click', async () => {
        const selected = root.querySelector('[data-select-category].bg-surface-container-low')
            || root.querySelector('[data-select-category]');
        if (!selected) {
            return;
        }
        const ok = await window.AnnigemsAlert?.confirmAction({
            title: `Delete ${selected.dataset.name}?`,
            text: selected.dataset.items === '0'
                ? 'This category will be removed if it has no sub-categories.'
                : `${selected.dataset.items} jewellery item(s) use this category. Delete will be blocked — deactivate instead.`,
            confirmButtonText: 'Delete',
        });
        if (!ok) {
            return;
        }
        const { ok: saved, json } = await send(`/branch/categories/${selected.dataset.uuid}`, 'POST', deleteBody());
        if (!saved) {
            window.AnnigemsAlert?.notifyError(json.message || 'Could not delete this category.');
            return;
        }
        window.location.reload();
    });

    root.querySelector('[data-add-sub]')?.addEventListener('click', () => {
        if (!selectedUuid) {
            return;
        }
        fillCatalogForm(subForm, {});
        subForm.dataset.uuid = '';
        subForm.querySelector('input[name="_method"]').value = 'POST';
        if (subModal.querySelector('h2')) {
            subModal.querySelector('h2').textContent = 'Add sub-category';
        }
        setModalOpen(subModal, true);
    });

    subRows?.addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-edit-sub]');
        const del = event.target.closest('[data-delete-sub]');
        const row = event.target.closest('tr');
        if (!row) {
            return;
        }
        if (edit) {
            fillCatalogForm(subForm, {
                name: row.dataset.name,
                description: row.dataset.description,
                active: row.dataset.active,
                sort: row.dataset.sort,
            });
            subForm.dataset.uuid = row.dataset.subUuid;
            subForm.querySelector('input[name="_method"]').value = 'PUT';
            if (subModal.querySelector('h2')) {
                subModal.querySelector('h2').textContent = 'Edit sub-category';
            }
            setModalOpen(subModal, true);
        }
        if (del) {
            const ok = await window.AnnigemsAlert?.confirmAction({
                title: `Delete ${row.dataset.name}?`,
                text: row.dataset.items === '0'
                    ? 'This sub-category will be removed.'
                    : `${row.dataset.items} jewellery item(s) use this sub-category. Delete will be blocked — deactivate instead.`,
                confirmButtonText: 'Delete',
            });
            if (!ok) {
                return;
            }
            const { ok: saved, json } = await send(
                `/branch/categories/${selectedUuid}/sub-categories/${row.dataset.subUuid}`,
                'POST',
                deleteBody()
            );
            if (!saved) {
                window.AnnigemsAlert?.notifyError(json.message || 'Could not delete this sub-category.');
                return;
            }
            loadSubs(selectedUuid);
        }
    });

    [categoryModal, subModal].forEach((modal) => {
        modal?.querySelectorAll('[data-modal-close], [data-modal-overlay]').forEach((el) => {
            el.addEventListener('click', () => setModalOpen(modal, false));
        });
    });

    categoryForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (window.AnnigemsForm && !window.AnnigemsForm.validateForm(categoryForm)) {
            return;
        }
        const uuid = categoryForm.dataset.uuid;
        const url = uuid ? `/branch/categories/${uuid}` : '/branch/categories';
        const body = new FormData(categoryForm);
        const submitter = categoryForm.querySelector('[type="submit"]');
        const loader = window.AnnigemsButton?.startButtonLoading(submitter, 'Saving...');
        const { ok, json } = await send(url, 'POST', body);
        if (!ok) {
            window.AnnigemsForm?.applyServerErrors(categoryForm, json.errors || {});
            window.AnnigemsAlert?.notifyError(json.message || 'Could not save the category.');
            loader?.restore();
            return;
        }
        window.location.reload();
    });

    subForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (window.AnnigemsForm && !window.AnnigemsForm.validateForm(subForm)) {
            return;
        }
        const uuid = subForm.dataset.uuid;
        const url = uuid
            ? `/branch/categories/${selectedUuid}/sub-categories/${uuid}`
            : `/branch/categories/${selectedUuid}/sub-categories`;
        const body = new FormData(subForm);
        const submitter = subForm.querySelector('[type="submit"]');
        const loader = window.AnnigemsButton?.startButtonLoading(submitter, 'Saving...');
        const { ok, json } = await send(url, 'POST', body);
        if (!ok) {
            window.AnnigemsForm?.applyServerErrors(subForm, json.errors || {});
            window.AnnigemsAlert?.notifyError(json.message || 'Could not save the sub-category.');
            loader?.restore();
            return;
        }
        setModalOpen(subModal, false);
        loader?.restore();
        loadSubs(selectedUuid);
    });
}

/**
 * Fill shared catalog fields.
 *
 * @param {HTMLFormElement} form
 * @param {object} values
 */
function fillCatalogForm(form, values) {
    form.reset();
    const name = form.querySelector('[name="name"]');
    const description = form.querySelector('[name="description"]');
    const active = form.querySelector('[name="is_active"]');
    const sort = form.querySelector('[name="sort_order"]');
    if (name) {
        name.value = values.name || '';
    }
    if (description) {
        description.value = values.description || '';
    }
    if (active) {
        active.value = values.active || '1';
    }
    if (sort) {
        sort.value = values.sort || '0';
    }
}

/**
 * Method-spoofed DELETE body.
 *
 * @returns {FormData}
 */
function deleteBody() {
    const body = new FormData();
    body.append('_method', 'DELETE');

    return body;
}

/**
 * @param {string} value
 * @returns {string}
 */
function escapeHtml(value) {
    return String(value || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}

/**
 * @param {string} value
 * @returns {string}
 */
function escapeAttr(value) {
    return escapeHtml(value).replaceAll('"', '&quot;');
}
