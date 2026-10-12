/**
 * Client-side form validation helpers.
 * Validates on blur and submit; shows inline field errors (never alert()).
 */

/**
 * Clear previous error state for a field.
 *
 * @param {HTMLElement} field
 */
function clearFieldError(field) {
    field.classList.remove('is-invalid', 'border-error');
    const container = field.closest('[data-field]') || field.parentElement;
    const errorEl = container?.querySelector('[data-error-for="' + field.name + '"]');
    if (errorEl) {
        errorEl.textContent = '';
        errorEl.classList.add('is-hidden', 'hidden');
    }
}

/**
 * Show an inline error under a field and mark the border red.
 *
 * @param {HTMLElement} field
 * @param {string} message
 */
function showFieldError(field, message) {
    field.classList.add('is-invalid', 'border-error');
    const container = field.closest('[data-field]') || field.parentElement;
    let errorEl = container?.querySelector('[data-error-for="' + field.name + '"]');
    if (!errorEl && container) {
        errorEl = document.createElement('p');
        errorEl.className = 'form-error';
        errorEl.setAttribute('data-error-for', field.name);
        container.appendChild(errorEl);
    }
    if (errorEl) {
        errorEl.textContent = message;
        errorEl.classList.remove('is-hidden', 'hidden');
    }
}

/**
 * Validate a single input against HTML5 + data-rule attributes.
 *
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} field
 * @returns {boolean}
 */
export function validateField(field) {
    clearFieldError(field);

    if (field.disabled || field.type === 'hidden') {
        return true;
    }

    if (field.required && !String(field.value || '').trim()) {
        showFieldError(field, field.dataset.errorRequired || 'This field is required.');
        return false;
    }

    if (field.type === 'email' && field.value) {
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(field.value)) {
            showFieldError(field, field.dataset.errorEmail || 'Enter a valid email address.');
            return false;
        }
    }

    if (field.minLength > 0 && field.value.length > 0 && field.value.length < field.minLength) {
        showFieldError(field, field.dataset.errorMinlength || `Must be at least ${field.minLength} characters.`);
        return false;
    }

    if (field.dataset.match) {
        const other = document.querySelector(field.dataset.match);
        if (other && field.value !== other.value) {
            showFieldError(field, field.dataset.errorMatch || 'Values do not match.');
            return false;
        }
    }

    if (field.dataset.pattern) {
        const re = new RegExp(field.dataset.pattern);
        if (field.value && !re.test(field.value)) {
            showFieldError(field, field.dataset.errorPattern || 'Invalid format.');
            return false;
        }
    }

    return true;
}

/**
 * Validate all fields inside a form.
 *
 * @param {HTMLFormElement} form
 * @returns {boolean}
 */
export function validateForm(form) {
    let valid = true;
    const fields = form.querySelectorAll('input, select, textarea');
    fields.forEach((field) => {
        if (!validateField(field)) {
            valid = false;
        }
    });
    return valid;
}

/**
 * Apply Laravel validation error bag ({ field: [messages] }) to a form.
 *
 * @param {HTMLFormElement} form
 * @param {Record<string, string[]|string>} errors
 */
export function applyServerErrors(form, errors = {}) {
    Object.entries(errors).forEach(([name, messages]) => {
        const field = form.querySelector(`[name="${name}"]`);
        if (!field) {
            return;
        }
        const message = Array.isArray(messages) ? messages[0] : messages;
        showFieldError(field, message);
    });
}

/**
 * Bind blur + submit validation for forms with [data-validate-form].
 */
export function bindFormValidation() {
    document.querySelectorAll('form[data-validate-form]').forEach((form) => {
        form.querySelectorAll('input, select, textarea').forEach((field) => {
            field.addEventListener('blur', () => validateField(field));
        });

        form.addEventListener('submit', (event) => {
            if (!validateForm(form)) {
                event.preventDefault();
                event.stopPropagation();
                const controller = form._loadingController;
                if (controller) {
                    controller.restore();
                }
            }
        });
    });
}

window.AnnigemsForm = {
    validateField,
    validateForm,
    applyServerErrors,
    bindFormValidation,
};
