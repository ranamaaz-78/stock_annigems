/**
 * USB keyboard-wedge barcode scanner helper.
 *
 * Hardware scanners type characters very quickly, then often send Enter
 * (or Tab). Many cheaper models send no terminator at all — they only dump
 * the code. Timing, prefix, suffix, and terminators come from config.
 *
 * When scan mode is ON, capture happens on the document: the operator does
 * not have to click the input first. Rapid bursts are treated as scans even
 * if focus is elsewhere. Slow typing in quantity/notes is left alone.
 *
 * Manual hardware checklist:
 * 1. Open Stone Intake — “Scanning on” is already active (green ready ring).
 * 2. Do not click any field. Scan a known packet — row appears with qty 1.
 * 3. Scan the same packet again — quantity becomes 2.
 * 4. Scan rapidly 5+ times — rows apply in order; the input does not stick.
 * 5. Click a quantity field and type slowly — the number is not overwritten.
 * 6. Click “Scanning off” — scans are ignored until turned back on.
 * 7. Unknown barcode — inline error; Register shortcut if permitted.
 * 8. Mute checkbox — no beep; stored with the server draft.
 */

/**
 * @typedef {object} ScannerConfig
 * @property {number} [inter_keystroke_ms]
 * @property {number} [idle_refocus_ms]
 * @property {string} [prefix]
 * @property {string} [suffix]
 * @property {string[]} [terminators]
 */

/** Minimum characters before an idle (no-Enter) burst is accepted as a scan. */
const MIN_BURST_LENGTH = 4;

/**
 * Strip configured prefix/suffix from a raw wedge payload.
 *
 * @param {string} raw
 * @param {ScannerConfig} config
 * @returns {string}
 */
export function normalizeScanPayload(raw, config) {
    let value = String(raw || '').trim();
    const prefix = config.prefix || '';
    const suffix = config.suffix || '';

    if (prefix && value.startsWith(prefix)) {
        value = value.slice(prefix.length);
    }
    if (suffix && value.endsWith(suffix)) {
        value = value.slice(0, value.length - suffix.length);
    }

    return value.trim();
}

/**
 * True when the key is a printable character the wedge would type.
 *
 * @param {KeyboardEvent} event
 * @returns {boolean}
 */
function isPrintableKey(event) {
    if (event.ctrlKey || event.metaKey || event.altKey) {
        return false;
    }
    return event.key.length === 1;
}

/**
 * Attach keyboard-wedge capture. Scan mode can run without focusing the input.
 *
 * @param {object} options
 * @param {HTMLInputElement} options.input
 * @param {ScannerConfig} options.config
 * @param {(code: string, meta: {manual: boolean}) => void|Promise<void>} options.onScan
 *        meta.manual is true when the operator typed the code and pressed
 *        Enter (or Add), false when a scanner dumped it.
 * @param {(el: EventTarget|null) => boolean} [options.isProtectedTarget]
 * @param {HTMLElement|null} [options.readyWrap]
 * @param {boolean} [options.enabled]
 * @param {boolean} [options.allowManualTyping] Slow typing stays in the field for typeahead.
 * @param {() => boolean} [options.shouldSubmitScan] Return false to let Enter pick a suggestion.
 * @returns {{ destroy: Function, focus: Function, submitManual: Function, setEnabled: Function, isEnabled: Function }}
 */
export function attachBarcodeScanner(options) {
    const input = options.input;
    const config = options.config || {};
    const interMs = Number(config.inter_keystroke_ms || 50);
    const idleMs = Number(config.idle_refocus_ms || 2500);
    const terminators = Array.isArray(config.terminators) && config.terminators.length
        ? config.terminators
        : ['Enter', 'Tab'];
    const isProtected = options.isProtectedTarget || (() => false);
    const readyWrap = options.readyWrap || input.closest('[data-scan-wrap]');
    const allowManualTyping = Boolean(options.allowManualTyping);

    let enabled = options.enabled !== false;
    let destroyed = false;
    let buffer = '';
    let lastKeyAt = 0;
    let idleFlushTimer = null;
    let refocusTimer = null;
    let ignoreInputEvent = false;
    let queue = Promise.resolve();

    /**
     * Write the visible field without treating it as a second scan source.
     *
     * @param {string} value
     */
    function setInputValue(value) {
        ignoreInputEvent = true;
        input.value = value;
        ignoreInputEvent = false;
    }

    /**
     * Green ready ring while scan mode is on.
     */
    function syncReadyState() {
        readyWrap?.classList.toggle('is-ready', enabled && !destroyed);
    }

    /**
     * Keep lookups in scan order without blocking further keystrokes.
     *
     * @param {string} code
     */
    function enqueue(code, meta = { manual: false }) {
        const payload = normalizeScanPayload(code, config);
        if (!payload) {
            return;
        }
        if (typeof options.onScan !== 'function') {
            return;
        }
        queue = queue.then(() => options.onScan(payload, meta)).catch(() => {});
    }

    /**
     * Finish the current buffer and clear the visible input.
     */
    function flush() {
        window.clearTimeout(idleFlushTimer);
        const fromBuffer = buffer;
        const fromInput = input.value;
        buffer = '';
        lastKeyAt = 0;
        setInputValue('');
        // Nothing in the key buffer means the characters were typed straight
        // into the field, i.e. by a person rather than dumped by a scanner.
        enqueue(fromBuffer || fromInput, { manual: fromBuffer === '' });
        if (enabled) {
            restoreFocus();
        }
    }

    /**
     * Complete the scan after the wedge stops typing — even with no Enter/Tab.
     */
    function scheduleIdleFlush() {
        window.clearTimeout(idleFlushTimer);
        idleFlushTimer = window.setTimeout(() => {
            if (buffer.trim().length >= MIN_BURST_LENGTH) {
                flush();
            }
        }, Math.max(interMs * 4, 120));
    }

    /**
     * Restore focus unless quantity/notes/name currently own the keyboard.
     */
    function restoreFocus() {
        if (destroyed || !enabled || isProtected(document.activeElement)) {
            return;
        }
        input.focus({ preventScroll: true });
    }

    /**
     * Restart idle refocus (used when scan mode is on).
     */
    function bumpRefocus() {
        window.clearTimeout(refocusTimer);
        if (!enabled) {
            return;
        }
        refocusTimer = window.setTimeout(restoreFocus, idleMs);
    }

    /**
     * @param {KeyboardEvent} event
     */
    function onDocumentKeyDown(event) {
        if (destroyed || !enabled) {
            return;
        }

        const target = event.target;
        const onScanInput = target === input;
        const protectedField = !onScanInput && isProtected(target);
        const now = Date.now();
        const gap = lastKeyAt ? now - lastKeyAt : Number.POSITIVE_INFINITY;
        const inBurst = gap <= interMs;

        if (terminators.includes(event.key)) {
            if (typeof options.shouldSubmitScan === 'function' && !options.shouldSubmitScan()) {
                return;
            }
            if (onScanInput || buffer.length > 0 || (inBurst && !protectedField)) {
                event.preventDefault();
                event.stopPropagation();
                flush();
            }
            return;
        }

        if (!isPrintableKey(event)) {
            return;
        }

        // Slow typing in qty / notes / date is the operator, not the scanner.
        if (protectedField && !inBurst && buffer === '') {
            return;
        }

        // Slow typing in the scan field is a name/code search, not a wedge dump.
        if (allowManualTyping && onScanInput && !inBurst && buffer === '') {
            return;
        }

        if (inBurst || onScanInput || !protectedField) {
            event.preventDefault();
            event.stopPropagation();
            // First character of a focused wedge dump was typed natively.
            if (inBurst && allowManualTyping && buffer === '') {
                buffer = input.value || '';
            }
            buffer += event.key;
            lastKeyAt = now;
            setInputValue(buffer);
            scheduleIdleFlush();
        }
    }

    /**
     * Some wedges paste the whole code, or type with Unidentified keys so
     * keydown never sees printable characters. The input event still fires.
     */
    function onNativeInput() {
        if (destroyed || !enabled || ignoreInputEvent) {
            return;
        }
        // Name search owns the input event when the operator is typing slowly.
        if (allowManualTyping) {
            return;
        }
        const value = input.value;
        if (String(value).trim().length >= MIN_BURST_LENGTH) {
            buffer = value;
            scheduleIdleFlush();
        }
    }

    /**
     * Clipboard / paste-mode scanners.
     *
     * @param {ClipboardEvent} event
     */
    function onPaste(event) {
        if (destroyed || !enabled) {
            return;
        }
        const text = event.clipboardData?.getData('text') || '';
        if (!text.trim()) {
            return;
        }
        event.preventDefault();
        buffer = text;
        setInputValue(text);
        flush();
    }

    /**
     * Lookup button / typed code uses the same add-row path.
     */
    function submitManual() {
        window.clearTimeout(idleFlushTimer);
        const typed = buffer || input.value;
        buffer = '';
        lastKeyAt = 0;
        setInputValue('');
        enqueue(typed, { manual: true });
        if (enabled) {
            restoreFocus();
        }
    }

    /**
     * Turn document-level capture on or off.
     *
     * @param {boolean} next
     */
    function setEnabled(next) {
        enabled = Boolean(next);
        syncReadyState();
        if (enabled) {
            restoreFocus();
        }
    }

    document.addEventListener('keydown', onDocumentKeyDown, true);
    input.addEventListener('input', onNativeInput);
    input.addEventListener('paste', onPaste);
    input.addEventListener('focus', syncReadyState);
    input.addEventListener('blur', () => {
        syncReadyState();
        bumpRefocus();
    });
    document.addEventListener('focusin', bumpRefocus, true);

    syncReadyState();
    if (enabled) {
        restoreFocus();
    }

    const api = {
        destroy() {
            destroyed = true;
            window.clearTimeout(idleFlushTimer);
            window.clearTimeout(refocusTimer);
            document.removeEventListener('keydown', onDocumentKeyDown, true);
            document.removeEventListener('focusin', bumpRefocus, true);
            input.removeEventListener('input', onNativeInput);
            input.removeEventListener('paste', onPaste);
            if (window.AnnigemsScanner === api) {
                window.AnnigemsScanner = null;
            }
        },
        focus: restoreFocus,
        submitManual,
        setEnabled,
        isEnabled() {
            return enabled;
        },
    };

    window.AnnigemsScanner = api;

    return api;
}

/**
 * Shared boot hook. Intake (and later POS) attach explicitly with their own rules.
 */
export function bindBarcodeScanners() {
    // Intentionally empty — pages call attachBarcodeScanner themselves.
}
