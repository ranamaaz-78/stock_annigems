/**
 * SweetAlert2 helpers for confirmations and result toasts.
 * Used system-wide instead of browser alert()/confirm().
 */
import Swal from 'sweetalert2';

const defaultToast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3200,
    timerProgressBar: true,
});

/**
 * Show a confirmation dialog before a destructive action.
 *
 * @param {object} options
 * @param {string} options.title
 * @param {string} options.text
 * @param {string} [options.confirmButtonText]
 * @param {string} [options.icon]
 * @returns {Promise<boolean>} true when the user confirms
 */
export async function confirmAction({
    title = 'Are you sure?',
    text = 'This action cannot be undone.',
    confirmButtonText = 'Confirm',
    icon = 'warning',
} = {}) {
    const result = await Swal.fire({
        title,
        text,
        icon,
        showCancelButton: true,
        confirmButtonColor: '#785600',
        cancelButtonColor: '#5d5e61',
        confirmButtonText,
        cancelButtonText: 'Cancel',
        reverseButtons: true,
    });

    return result.isConfirmed;
}

/**
 * Success toast / popup after a completed action.
 *
 * @param {string} message
 * @param {boolean} [toast=true]
 */
export function notifySuccess(message, toast = true) {
    if (toast) {
        return defaultToast.fire({ icon: 'success', title: message });
    }

    return Swal.fire({
        icon: 'success',
        title: 'Success',
        text: message,
        confirmButtonColor: '#785600',
    });
}

/**
 * Error popup with a friendly explanation (never raw exceptions).
 *
 * @param {string} message
 * @param {boolean} [toast=false]
 */
export function notifyError(message, toast = false) {
    if (toast) {
        return defaultToast.fire({ icon: 'error', title: message });
    }

    return Swal.fire({
        icon: 'error',
        title: 'Something went wrong',
        text: message,
        confirmButtonColor: '#785600',
    });
}

/**
 * Modal showing one-time credentials with copy support.
 *
 * @param {object} credentials
 * @param {string} credentials.email
 * @param {string} credentials.password
 * @param {string} [credentials.name]
 */
export async function showCredentialsModal({ email, password, name }) {
    const html = `
        <div class="text-left text-sm space-y-3">
            ${name ? `<p><strong>Name:</strong> ${escapeHtml(name)}</p>` : ''}
            <p><strong>Email:</strong> <code id="cred-email">${escapeHtml(email)}</code></p>
            <p><strong>Temporary password:</strong> <code id="cred-password">${escapeHtml(password)}</code></p>
            <p class="text-xs text-gray-600">Copy these now. The password will not be shown again.</p>
        </div>
    `;

    await Swal.fire({
        title: 'Account credentials',
        html,
        icon: 'info',
        confirmButtonColor: '#785600',
        confirmButtonText: 'Copy password & close',
        showCancelButton: true,
        cancelButtonText: 'Close',
        preConfirm: async () => {
            try {
                await navigator.clipboard.writeText(password);
            } catch (e) {
                // Clipboard may be blocked; still allow closing.
            }
        },
    });
}

/**
 * Escape HTML entities for safe insertion into SweetAlert HTML.
 *
 * @param {string} value
 * @returns {string}
 */
function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/**
 * Confirm and collect a required text reason (void invoice, etc.).
 *
 * @param {object} options
 * @param {string} options.title
 * @param {string} options.text
 * @param {string} [options.confirmButtonText]
 * @param {string} [options.inputLabel]
 * @param {number} [options.minLength]
 * @param {string} [options.icon]
 * @returns {Promise<string|null>} Trimmed text when confirmed, otherwise null
 */
export async function promptText({
    title = 'Are you sure?',
    text = '',
    confirmButtonText = 'Confirm',
    inputLabel = '',
    minLength = 0,
    icon = 'warning',
} = {}) {
    const result = await Swal.fire({
        title,
        text,
        icon,
        input: 'textarea',
        inputLabel,
        inputValidator: (value) => {
            if (minLength > 0 && (!value || value.trim().length < minLength)) {
                return `Please enter at least ${minLength} characters.`;
            }

            return null;
        },
        showCancelButton: true,
        confirmButtonColor: '#785600',
        cancelButtonColor: '#5d5e61',
        confirmButtonText,
        cancelButtonText: 'Cancel',
        reverseButtons: true,
    });

    return result.isConfirmed ? String(result.value || '').trim() : null;
}

/**
 * Ask what to do with uploads whose names already exist.
 *
 * @param {object} options
 * @param {string[]} options.names Names already in the folder
 * @returns {Promise<'replace'|'skip'|null>} The choice, or null to cancel the upload
 */
export async function chooseDuplicateAction({ names = [] } = {}) {
    const shown = names.slice(0, 8).map(escapeHtml).join(', ');
    const more = names.length > 8 ? ` and ${names.length - 8} more` : '';

    const result = await Swal.fire({
        title: names.length === 1 ? 'This name already exists' : `${names.length} names already exist`,
        html: `<p><strong>${shown}</strong>${more}</p><p>Replace the existing pictures with the new files, or skip these and upload only the rest?</p>`,
        icon: 'question',
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: 'Replace',
        denyButtonText: 'Skip these',
        cancelButtonText: 'Cancel upload',
        confirmButtonColor: '#785600',
        denyButtonColor: '#5d5e61',
        cancelButtonColor: '#b3b3b3',
        reverseButtons: true,
    });

    if (result.isConfirmed) {
        return 'replace';
    }

    return result.isDenied ? 'skip' : null;
}

window.AnnigemsAlert = {
    confirmAction,
    chooseDuplicateAction,
    promptText,
    notifySuccess,
    notifyError,
    showCredentialsModal,
};
