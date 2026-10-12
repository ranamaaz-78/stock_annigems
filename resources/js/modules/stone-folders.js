/**
 * Material folder view switch.
 *
 * Toggles the folder grid between 150px boxes and full-width rows, and
 * remembers the choice in localStorage so the operator's preference
 * survives navigation and reloads. No request is made — the markup is
 * identical in both modes, only the layout class changes.
 */

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
 * Page boot for the stone folder grid.
 */
export function bindStoneFolders() {
    document.querySelectorAll('[data-stone-folders]').forEach(bindFolderPanel);
}
