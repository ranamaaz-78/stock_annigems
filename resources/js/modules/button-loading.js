/**
 * Reusable submit-button loading / disable helper.
 * Prevents double submission across the entire application.
 */

/**
 * Put a button into its loading state immediately.
 *
 * @param {HTMLButtonElement|HTMLInputElement} button
 * @param {string} [loadingText='Please wait...']
 * @returns {{ restore: Function }}
 */
export function startButtonLoading(button, loadingText = 'Please wait...') {
    if (!button) {
        return { restore: () => {} };
    }

    const originalHtml = button.innerHTML;
    const originalDisabled = button.disabled;

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.dataset.originalHtml = originalHtml;

    if (button.tagName === 'BUTTON') {
        button.innerHTML = `<span class="inline-flex items-center gap-2"><span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span><span>${loadingText}</span></span>`;
    } else {
        button.value = loadingText;
    }

    return {
        /**
         * Restore the button to its original label and enabled state.
         */
        restore() {
            button.disabled = originalDisabled;
            button.removeAttribute('aria-busy');
            if (button.tagName === 'BUTTON') {
                button.innerHTML = button.dataset.originalHtml || originalHtml;
            }
        },
    };
}

/**
 * Forms whose own script confirms before submitting (see stone-form.js).
 */
const CONFIRMS_FIRST = '[data-confirm-delete-stone], [data-confirm-delete-folder], [data-confirm-deactivate]';

/**
 * Wire all forms marked with [data-loading-form] to disable the submit button on submit.
 * On native form posts the page navigates away; on AJAX the caller should call restore().
 */
export function bindLoadingForms() {
    document.querySelectorAll('form[data-loading-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            // These forms ask "are you sure?" first and start the loading state
            // themselves once the answer is yes; starting it here would show
            // "Saving..." behind the question, and leave it there on Cancel.
            if (form.matches(CONFIRMS_FIRST)) {
                return;
            }

            const submitter = event.submitter || form.querySelector('[type="submit"]');
            if (!submitter || submitter.disabled) {
                return;
            }

            const loadingText = submitter.dataset.loadingText || 'Saving...';
            const controller = startButtonLoading(submitter, loadingText);
            form._loadingController = controller;
        });
    });
}

/**
 * Bind click handlers for buttons with [data-loading-button].
 */
export function bindLoadingButtons() {
    document.querySelectorAll('[data-loading-button]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }
            const loadingText = button.dataset.loadingText || 'Working...';
            button._loadingController = startButtonLoading(button, loadingText);
        });
    });
}

window.AnnigemsButton = {
    startButtonLoading,
    bindLoadingForms,
    bindLoadingButtons,
};
