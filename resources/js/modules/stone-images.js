/**
 * Stones image folder.
 *
 * 100×100 tiles, click-to-enlarge, drag-and-drop and an Upload files button.
 * The filename under each tile renames in place (Enter saves, Escape cancels).
 *
 * Endpoints:
 *   POST   stone-images.store          — multipart files[]
 *   PATCH  stone-images.update         — JSON { name }
 *   DELETE stone-images.destroy
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
 * JSON request helper. File uploads pass FormData and must not set Content-Type.
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
 * Bind the Stones image folder. No-op on every other screen.
 */
export function bindStoneImages() {
    const root = document.getElementById('stone-images');
    if (!root) return;

    const uploadUrl = root.dataset.uploadUrl || '';
    const grid = root.querySelector('[data-grid]');
    const empty = root.querySelector('[data-empty]');
    const dropzone = root.querySelector('[data-dropzone]');
    const fileInput = root.querySelector('[data-file-input]');
    const uploadBtn = root.querySelector('[data-upload-trigger]');
    const lightbox = document.querySelector('[data-lightbox]');
    const lightboxImage = lightbox?.querySelector('[data-lightbox-image]');
    const lightboxCaption = lightbox?.querySelector('[data-lightbox-caption]');

    /** @type {Array<{uuid: string, name: string, url: string}>} */
    let images = [];
    try {
        images = JSON.parse(root.dataset.images || '[]');
    } catch (error) {
        console.error('stone-images: could not parse the folder', error);
    }

    /**
     * @param {string} value
     */
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Show the empty hint only when the folder has no tiles.
     */
    function syncEmpty() {
        empty?.classList.toggle('is-hidden', images.length > 0);
    }

    /**
     * Paint every tile. Names stay as text until the operator clicks them.
     */
    function render() {
        if (!grid) return;
        grid.innerHTML = images.map((image) => `
            <article class="stone-folder__tile" data-uuid="${escapeHtml(image.uuid)}">
                <button type="button" class="stone-folder__thumb" data-open title="${escapeHtml(image.name)}">
                    <img src="${escapeHtml(image.url)}" alt="${escapeHtml(image.name)}" width="100" height="100">
                </button>
                <button type="button" class="stone-folder__name" data-rename title="Click to rename">${escapeHtml(image.name)}</button>
                <button type="button" class="stone-folder__delete" data-delete aria-label="Delete ${escapeHtml(image.name)}">
                    <span class="material-symbols-outlined icon icon--xs">close</span>
                </button>
            </article>
        `).join('');
        syncEmpty();
    }

    /**
     * @param {string} uuid
     */
    function findImage(uuid) {
        return images.find((image) => image.uuid === uuid);
    }

    /**
     * Open the large preview.
     *
     * @param {{name: string, url: string}} image
     */
    function openLightbox(image) {
        if (!lightbox || !lightboxImage) return;
        lightboxImage.src = image.url;
        lightboxImage.alt = image.name;
        if (lightboxCaption) lightboxCaption.textContent = image.name;
        lightbox.hidden = false;
    }

    function closeLightbox() {
        if (!lightbox || !lightboxImage) return;
        lightbox.hidden = true;
        lightboxImage.removeAttribute('src');
    }

    /**
     * POST stone-images.store with one or more files.
     *
     * @param {FileList|File[]} fileList
     * @param {HTMLButtonElement|null} button
     */
    async function uploadFiles(fileList, button) {
        const files = [...fileList].filter((file) => file && file.size >= 0);
        if (!files.length) return;

        const loading = button ? window.AnnigemsButton?.startButtonLoading(button, 'Uploading...') : null;

        // Names already in the folder need an answer first: replace or skip.
        let onDuplicate = null;
        const checked = await fetchJson(`${uploadUrl}/check`, {
            method: 'POST',
            body: JSON.stringify({ names: files.map((file) => file.name) }),
        });
        const existing = checked.ok ? (checked.json.data?.existing || []) : [];
        loading?.restore();

        if (existing.length) {
            onDuplicate = await window.AnnigemsAlert?.chooseDuplicateAction({ names: existing });
            if (!onDuplicate) return;
        }

        const body = new FormData();
        files.forEach((file) => body.append('files[]', file));
        if (onDuplicate) body.append('on_duplicate', onDuplicate);

        const sending = button ? window.AnnigemsButton?.startButtonLoading(button, 'Uploading...') : null;
        const { ok, json } = await fetchJson(uploadUrl, { method: 'POST', body });
        sending?.restore();

        if (ok && Array.isArray(json.data?.images)) {
            const incoming = json.data.images;
            // A replaced picture keeps its place but gets its new address, so
            // the tile shows the new file; brand-new ones go to the front.
            const fresh = incoming.filter((row) => !images.some((known) => known.uuid === row.uuid));
            images = [
                ...fresh,
                ...images.map((known) => incoming.find((row) => row.uuid === known.uuid) || known),
            ];
            render();

            const failed = json.data.errors && Object.keys(json.data.errors).length;
            if (failed) {
                window.AnnigemsAlert?.notifyError(Object.values(json.data.errors).flat().join(' ') || json.message);
            } else {
                window.AnnigemsAlert?.notifySuccess(json.message || 'Images uploaded.');
            }
            return;
        }

        const fieldErrors = json.errors ? Object.values(json.errors).flat() : [];
        window.AnnigemsAlert?.notifyError(fieldErrors.join(' ') || json.message || 'The images could not be uploaded.');
    }

    /**
     * Turn a filename into an inline editor. Enter saves, Escape restores.
     *
     * @param {HTMLButtonElement} nameButton
     */
    function beginRename(nameButton) {
        const tile = nameButton.closest('[data-uuid]');
        const image = findImage(tile?.dataset.uuid || '');
        if (!image || nameButton.dataset.editing === '1') return;

        nameButton.dataset.editing = '1';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'stone-folder__rename';
        input.value = image.name;
        input.maxLength = 180;
        input.setAttribute('aria-label', 'File name');
        nameButton.replaceWith(input);
        input.focus();
        input.select();

        let settled = false;

        /**
         * @param {boolean} save
         */
        async function finish(save) {
            if (settled) return;
            settled = true;
            const next = input.value.trim();
            if (!save || next === '' || next === image.name) {
                render();
                return;
            }

            const { ok, json } = await fetchJson(`${uploadUrl}/${image.uuid}`, {
                method: 'PATCH',
                body: JSON.stringify({ name: next }),
            });

            if (ok && json.data?.image) {
                image.name = json.data.image.name;
                window.AnnigemsAlert?.notifySuccess(json.message || 'Name updated.');
            } else {
                const fieldErrors = json.errors ? Object.values(json.errors).flat() : [];
                window.AnnigemsAlert?.notifyError(fieldErrors.join(' ') || json.message || 'That name could not be saved.');
            }
            render();
        }

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                finish(true);
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                finish(false);
            }
        });
        input.addEventListener('blur', () => finish(true));
    }

    /**
     * DELETE stone-images.destroy after a confirmation.
     *
     * @param {string} uuid
     */
    async function removeImage(uuid) {
        const image = findImage(uuid);
        if (!image) return;

        const confirmed = await window.AnnigemsAlert?.confirmAction({
            title: 'Delete this image?',
            text: `${image.name} will be removed from the folder.`,
            confirmButtonText: 'Delete',
        });
        if (!confirmed) return;

        const { ok, json } = await fetchJson(`${uploadUrl}/${uuid}`, { method: 'DELETE' });
        if (!ok) {
            window.AnnigemsAlert?.notifyError(json.message || 'The image could not be deleted.');
            return;
        }

        images = images.filter((row) => row.uuid !== uuid);
        render();
        window.AnnigemsAlert?.notifySuccess(json.message || 'Image deleted.');
    }

    grid?.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const open = target.closest('[data-open]');
        if (open) {
            const image = findImage(open.closest('[data-uuid]')?.dataset.uuid || '');
            if (image) openLightbox(image);
            return;
        }

        const rename = target.closest('[data-rename]');
        if (rename instanceof HTMLButtonElement) {
            beginRename(rename);
            return;
        }

        const remove = target.closest('[data-delete]');
        if (remove) {
            removeImage(remove.closest('[data-uuid]')?.dataset.uuid || '');
        }
    });

    uploadBtn?.addEventListener('click', () => fileInput?.click());

    fileInput?.addEventListener('change', () => {
        if (fileInput.files?.length) {
            uploadFiles(fileInput.files, uploadBtn);
        }
        fileInput.value = '';
    });

    ['dragenter', 'dragover'].forEach((name) => {
        dropzone?.addEventListener(name, (event) => {
            event.preventDefault();
            dropzone.classList.add('is-dropping');
        });
    });

    ['dragleave', 'drop'].forEach((name) => {
        dropzone?.addEventListener(name, (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dropping');
        });
    });

    dropzone?.addEventListener('drop', (event) => {
        const files = event.dataTransfer?.files;
        if (files?.length) {
            uploadFiles(files, uploadBtn);
        }
    });

    lightbox?.querySelector('[data-lightbox-close]')?.addEventListener('click', closeLightbox);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && lightbox && !lightbox.hidden) {
            closeLightbox();
        }
    });

    render();
}
