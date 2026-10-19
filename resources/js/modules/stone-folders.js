/**
 * Material folder view switch.
 *
 * Toggles the folder grid between 150px boxes and full-width rows, and
 * remembers the choice in localStorage so the operator's preference
 * survives navigation and reloads. No request is made — the markup is
 * identical in both modes, only the layout class changes.
 */

import { t } from './i18n';

/** localStorage key holding "box" or "list". */
const STORAGE_KEY = 'annigems.stones.folderView';

/**
 * Read the stored preference, defaulting to boxes.
 *
 * Private-mode browsers throw on localStorage access, so a failure just
 * falls back to the default rather than breaking the page.
 *
 * @returns {'box'|'list'}
 */
function storedView() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'list' ? 'list' : 'box';
    } catch (error) {
        return 'box';
    }
}

/**
 * Persist the preference, ignoring storage failures.
 *
 * @param {'box'|'list'} view
 */
function storeView(view) {
    try {
        window.localStorage.setItem(STORAGE_KEY, view);
    } catch (error) {
        // Preference is a convenience; losing it must not break the switch.
    }
}

/**
 * Wire one folder panel to its view switch.
 *
 * @param {HTMLElement} root The [data-stone-folders] panel
 */
function bindFolderPanel(root) {
    const list = root.querySelector('[data-folder-list]');
    const buttons = [...root.querySelectorAll('[data-folder-view]')];

    if (!list || buttons.length === 0) {
        return;
    }

    /**
     * Apply a view to the grid and mark the matching button active.
     *
     * @param {'box'|'list'} view
     */
    const apply = (view) => {
        list.classList.toggle('is-list', view === 'list');
        buttons.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.folderView === view);
            button.setAttribute('aria-pressed', String(button.dataset.folderView === view));
        });
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const view = button.dataset.folderView === 'list' ? 'list' : 'box';
            apply(view);
            storeView(view);
        });
    });

    apply(storedView());
}

/**
 * Turn a folder's name into an inline editor. Enter saves, Escape restores.
 *
 * Saving submits the tile's hidden rename form, so the server does the
 * checks and the page comes back with the result. An unchanged or empty
 * name just puts the label back without a request.
 *
 * @param {HTMLButtonElement} nameButton The [data-folder-rename] label
 */
function beginRename(nameButton) {
    const tile = nameButton.closest('[data-folder-tile]');
    const form = tile?.querySelector('[data-folder-rename-form]');
    const nameField = form?.querySelector('input[name="name"]');
    if (!(form instanceof HTMLFormElement) || !(nameField instanceof HTMLInputElement)) {
        return;
    }

    const current = nameField.value;
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'input-field folder-tile__rename';
    input.value = current;
    input.maxLength = 120;
    input.setAttribute('aria-label', t('Folder name'));
    nameButton.replaceWith(input);
    input.focus();
    input.select();

    let settled = false;

    /**
     * @param {boolean} save
     */
    const finish = (save) => {
        if (settled) return;
        settled = true;

        const next = input.value.trim();
        if (!save || next === '' || next === current) {
            input.replaceWith(nameButton);
            return;
        }

        nameField.value = next;
        form.submit();
    };

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
 * Wire the click on any editable folder name, including ones drawn later.
 *
 * @param {HTMLElement} root The [data-stone-folders] panel
 */
function bindFolderRename(root) {
    root.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const button = target?.closest('[data-folder-rename]');
        if (button instanceof HTMLButtonElement) {
            beginRename(button);
        }
    });
}

/**
 * Page boot for the stone folder grid.
 */
export function bindStoneFolders() {
    document.querySelectorAll('[data-stone-folders]').forEach((root) => {
        bindFolderPanel(root);
        bindFolderRename(root);
    });
}
