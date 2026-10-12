/**
 * Shared modal open/close behaviour for <x-modal> and <x-dialog> dialogs.
 *
 * Markup contract (see resources/views/components/modal.blade.php):
 *   - the dialog root carries the id and the `hidden` class while closed
 *   - `[data-modal-open="<id>"]`  opens that dialog
 *   - `[data-modal-close]` / `[data-modal-overlay]` inside a dialog close it
 *
 * Exposed on window.AnnigemsModal so inline scripts and other modules can
 * open a dialog without re-implementing the toggle.
 */

/**
 * Show a modal by element id. No-op when the id does not exist.
 *
 * @param {string} id
 */
export function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;

    modal.classList.remove('hidden');
    // Move focus into the dialog so keyboard users land inside it.
    modal.querySelector('input, select, textarea, button')?.focus();
}

/**
 * Hide a modal by element id or element reference.
 *
 * @param {string|HTMLElement} target
 */
export function closeModal(target) {
    const modal = typeof target === 'string' ? document.getElementById(target) : target;
    if (!modal) return;

    modal.classList.add('hidden');
}

let escapeBound = false;

/**
 * Bind openers, close buttons, overlay clicks, and Escape once per page.
 */
export function bindModals() {
    // The page's content column is its own stacking context, so a dialog left
    // inside it is drawn under the sidebar and top bar whatever its z-index.
    // Moving it to <body> puts it above them. Listeners and ids travel with it.
    document.querySelectorAll('.dialog').forEach((dialog) => {
        if (dialog.parentElement !== document.body) {
            document.body.appendChild(dialog);
        }
    });

    document.querySelectorAll('[data-modal-open]').forEach((trigger) => {
        trigger.addEventListener('click', () => openModal(trigger.dataset.modalOpen));
    });

    document.querySelectorAll('[data-modal-close], [data-modal-overlay]').forEach((el) => {
        el.addEventListener('click', () => closeModal(el.closest('[role="dialog"]')));
    });

    if (escapeBound) {
        return;
    }
    escapeBound = true;

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        document.querySelectorAll('[role="dialog"]:not(.hidden)').forEach((modal) => closeModal(modal));
    });
}

window.AnnigemsModal = { openModal, closeModal, bindModals };
