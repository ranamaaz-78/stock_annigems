/**
 * Admin shell chrome: full/mini sidebar, module accordions, and POS-style
 * fullscreen. Persistence is localStorage so density and kiosk survive navigation.
 */

import { t } from './i18n';

const STORAGE_DENSITY = 'annigems.sidebar';
const STORAGE_OPEN = 'annigems.navOpen';
const STORAGE_FULLSCREEN = 'annigems.fullscreen';

/**
 * Current sidebar density from the documentElement class (set before paint).
 *
 * @returns {'full'|'mini'}
 */
function density() {
    return document.documentElement.classList.contains('is-sidebar-mini') ? 'mini' : 'full';
}

/**
 * Persist and apply full vs mini sidebar. Mini is ignored on small viewports
 * in CSS; the class is still stored so desktop restores the choice.
 *
 * @param {'full'|'mini'} mode
 */
function setDensity(mode) {
    document.documentElement.classList.toggle('is-sidebar-mini', mode === 'mini');
    try {
        localStorage.setItem(STORAGE_DENSITY, mode);
    } catch (e) {
        // Private mode can throw; density still applies for this page.
    }
    syncDensityButton();
}

/**
 * Update the Mini/Full control label and pressed state.
 */
function syncDensityButton() {
    const mini = density() === 'mini';
    document.querySelectorAll('[data-shell-density]').forEach((button) => {
        button.setAttribute('aria-pressed', mini ? 'true' : 'false');
        button.title = mini ? t('Expand sidebar') : t('Collapse sidebar');
        const icon = button.querySelector('[data-shell-density-icon]');
        if (icon) {
            icon.textContent = mini ? 'chevron_right' : 'chevron_left';
        }
    });
}

/**
 * Remember kiosk fullscreen across page loads. The browser Fullscreen API
 * cannot restart without a click; the layout class can, and that is what
 * staff mean by "stay in full screen" after changing screens.
 *
 * @param {boolean} on
 */
function persistFullscreen(on) {
    try {
        localStorage.setItem(STORAGE_FULLSCREEN, on ? '1' : '0');
    } catch (e) {
        // Private mode can throw; the class still applies for this page.
    }
}

/**
 * Stored kiosk preference (set before paint in the boot snippet).
 *
 * @returns {boolean}
 */
function storedFullscreen() {
    try {
        return localStorage.getItem(STORAGE_FULLSCREEN) === '1';
    } catch (e) {
        return false;
    }
}

/**
 * Browser fullscreen plus the kiosk class (hides the topbar).
 *
 * @returns {boolean}
 */
function isFullscreen() {
    return Boolean(document.fullscreenElement || document.webkitFullscreenElement)
        || document.documentElement.classList.contains('is-app-fullscreen')
        || storedFullscreen();
}

/**
 * Enter or leave POS-style fullscreen and keep the choice after navigation.
 *
 * @param {boolean} on
 */
async function setFullscreen(on) {
    const root = document.documentElement;
    persistFullscreen(on);
    root.classList.toggle('is-app-fullscreen', on);
    if (on) {
        markKioskHistory();
    }

    try {
        if (on && !document.fullscreenElement && !document.webkitFullscreenElement) {
            if (root.requestFullscreen) {
                await root.requestFullscreen();
            } else if (root.webkitRequestFullscreen) {
                root.webkitRequestFullscreen();
            }
        }
        if (!on) {
            if (document.exitFullscreen && document.fullscreenElement) {
                await document.exitFullscreen();
            } else if (document.webkitExitFullscreen && document.webkitFullscreenElement) {
                document.webkitExitFullscreen();
            }
        }
    } catch (e) {
        // Permission or iframe denial — kiosk class already reflects intent.
    }

    syncFullscreenButton();
}

/**
 * Update the Full screen control after API or Esc changes.
 */
function syncFullscreenButton() {
    const on = isFullscreen();
    document.querySelectorAll('[data-shell-fullscreen]').forEach((button) => {
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.title = on ? t('Exit full screen') : t('Full screen');
        const icon = button.querySelector('[data-shell-fullscreen-icon]');
        const label = button.querySelector('[data-shell-fullscreen-label]');
        if (icon) {
            icon.textContent = on ? 'fullscreen_exit' : 'fullscreen';
        }
        if (label) {
            label.textContent = on ? t('Exit full screen') : t('Full screen');
        }
    });
}

/**
 * Saved accordion ids, plus any group that contains the current page.
 *
 * @returns {string[]}
 */
function openGroupIds() {
    let stored = [];
    try {
        stored = JSON.parse(localStorage.getItem(STORAGE_OPEN) || '[]');
    } catch (e) {
        stored = [];
    }
    if (!Array.isArray(stored)) {
        stored = [];
    }

    document.querySelectorAll('.sa-sidebar__group.is-current[data-nav-group]').forEach((group) => {
        stored.push(group.getAttribute('data-nav-group'));
    });

    return [...new Set(stored.filter(Boolean))];
}

/**
 * Persist which modules the user has expanded.
 */
function saveOpenGroups() {
    const ids = [...document.querySelectorAll('.sa-sidebar__group.is-open[data-nav-group]')]
        .map((group) => group.getAttribute('data-nav-group'))
        .filter(Boolean);
    try {
        localStorage.setItem(STORAGE_OPEN, JSON.stringify(ids));
    } catch (e) {
        // Ignore quota / private-mode failures.
    }
}

/**
 * Expand or collapse one module group.
 *
 * @param {HTMLElement} group
 * @param {boolean} open
 */
function setGroupOpen(group, open) {
    const toggle = group.querySelector(':scope > [data-nav-toggle]');
    group.classList.toggle('is-open', open);
    if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
}

/**
 * Restore accordion state, keeping the active module open.
 */
function restoreGroups() {
    const open = new Set(openGroupIds());
    document.querySelectorAll('.sa-sidebar__group[data-nav-group]').forEach((group) => {
        const id = group.getAttribute('data-nav-group');
        setGroupOpen(group, open.has(id) || group.classList.contains('is-current'));
    });
}

/**
 * Close the mobile drawer overlay.
 */
function closeMobileNav() {
    document.documentElement.classList.remove('is-sidebar-open');
}

/** Document-level chrome is bound once; kiosk visits replace <body> only. */
let chromeBound = false;
let kioskVisiting = false;

/**
 * Whether POS kiosk / native fullscreen should keep this document alive.
 *
 * @returns {boolean}
 */
function kioskActive() {
    return storedFullscreen()
        || document.documentElement.classList.contains('is-app-fullscreen')
        || Boolean(document.fullscreenElement || document.webkitFullscreenElement);
}

/**
 * Mark the current history entry so Back stays in-document after kiosk visits.
 */
function markKioskHistory() {
    const current = history.state && typeof history.state === 'object' ? history.state : {};
    if (current.kiosk) {
        return;
    }
    history.replaceState({ ...current, kiosk: true }, '', window.location.href);
}

/**
 * Same-origin GET/POST that would otherwise unload the fullscreen document.
 *
 * @param {URL} url
 * @returns {boolean}
 */
function isInAppUrl(url) {
    if (url.origin !== window.location.origin) {
        return false;
    }

    return url.protocol === 'http:' || url.protocol === 'https:';
}

/**
 * Re-run inline body scripts (Blade @stack('scripts')) after a body swap.
 * Vite's bundle lives in <head> and must not be injected again.
 *
 * @param {ParentNode} root
 */
function activateScripts(root) {
    root.querySelectorAll('script').forEach((old) => {
        const src = old.getAttribute('src') || '';
        if (src.includes('/@vite') || src.includes('/build/') || src.includes('/hot')) {
            old.remove();
            return;
        }
        const script = document.createElement('script');
        [...old.attributes].forEach((attr) => script.setAttribute(attr.name, attr.value));
        script.textContent = old.textContent;
        old.replaceWith(script);
    });
}

/**
 * Fetch a screen and swap <body> so documentElement stays in native fullscreen.
 *
 * @param {string} href
 * @param {{ method?: string, body?: FormData|null, skipHistory?: boolean }} [options]
 */
async function kioskVisit(href, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const skipHistory = Boolean(options.skipHistory);

    if (kioskVisiting) {
        return;
    }
    kioskVisiting = true;
    document.documentElement.classList.add('is-kiosk-loading');

    try {
        window.AnnigemsScanner?.destroy?.();
        window.AnnigemsSelect?.destroySelects?.(document);

        const headers = {
            Accept: 'text/html, application/xhtml+xml',
            'X-Requested-With': 'XMLHttpRequest',
        };
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (token && method !== 'GET') {
            headers['X-CSRF-TOKEN'] = token;
        }

        const init = {
            method,
            credentials: 'same-origin',
            redirect: 'follow',
            headers,
        };
        if (options.body && method !== 'GET') {
            init.body = options.body;
        }

        const response = await fetch(href, init);
        const type = response.headers.get('content-type') || '';
        if (!type.includes('text/html')) {
            window.location.assign(response.url || href);
            return;
        }

        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');

        // Login / print / error layouts are a different shell — leave kiosk.
        if (!doc.querySelector('[data-shell-sidebar]')) {
            persistFullscreen(false);
            document.documentElement.classList.remove('is-app-fullscreen');
            try {
                if (document.exitFullscreen && document.fullscreenElement) {
                    await document.exitFullscreen();
                }
            } catch (e) {
                // Ignore.
            }
            window.location.assign(response.url || href);
            return;
        }

        document.title = doc.title;
        const nextToken = doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const liveToken = document.querySelector('meta[name="csrf-token"]');
        if (nextToken && liveToken) {
            liveToken.setAttribute('content', nextToken);
        }

        document.body.replaceWith(doc.body);
        activateScripts(document.body);

        markKioskHistory();
        if (!skipHistory) {
            history.pushState({ kiosk: true }, '', response.url || href);
        }

        closeMobileNav();
        window.AnnigemsBoot?.();
    } catch (e) {
        window.location.assign(href);
    } finally {
        kioskVisiting = false;
        document.documentElement.classList.remove('is-kiosk-loading');
    }
}

/**
 * Re-enter the Fullscreen API during a real click, then swap the screen.
 *
 * @returns {Promise<void>}
 */
async function ensureNativeFullscreen() {
    const root = document.documentElement;
    if (document.fullscreenElement || document.webkitFullscreenElement) {
        return;
    }
    try {
        if (root.requestFullscreen) {
            await root.requestFullscreen();
        } else if (root.webkitRequestFullscreen) {
            root.webkitRequestFullscreen();
        }
    } catch (e) {
        // Gesture may be consumed; kiosk layout still holds.
    }
}

/**
 * @param {MouseEvent} event
 * @param {HTMLAnchorElement} link
 * @returns {boolean}
 */
function shouldKioskFollowLink(event, link) {
    if (event.defaultPrevented || event.button !== 0) {
        return false;
    }
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false;
    }
    if (link.hasAttribute('download')) {
        return false;
    }
    if (link.target && link.target !== '_self') {
        return false;
    }
    const raw = link.getAttribute('href');
    if (!raw || raw.startsWith('#')) {
        return false;
    }

    try {
        const url = new URL(link.href, window.location.href);
        if (!isInAppUrl(url)) {
            return false;
        }
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) {
            return false;
        }
    } catch (e) {
        return false;
    }

    return true;
}

/**
 * Document-level listeners that survive a kiosk <body> swap.
 */
function bindShellChrome() {
    document.addEventListener('click', (event) => {
        const densityBtn = event.target.closest('[data-shell-density]');
        if (densityBtn) {
            event.preventDefault();
            setDensity(density() === 'mini' ? 'full' : 'mini');
            return;
        }

        const fullBtn = event.target.closest('[data-shell-fullscreen]');
        if (fullBtn) {
            event.preventDefault();
            setFullscreen(!isFullscreen());
            return;
        }

        const toggle = event.target.closest('[data-nav-toggle]');
        if (toggle) {
            const group = toggle.closest('.sa-sidebar__group');
            if (group) {
                setGroupOpen(group, !group.classList.contains('is-open'));
                saveOpenGroups();
            }
            return;
        }

        if (event.target.closest('[data-shell-mobile-toggle]')) {
            document.documentElement.classList.toggle('is-sidebar-open');
            return;
        }

        if (event.target.closest('[data-shell-backdrop]')) {
            closeMobileNav();
        }
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link) {
            return;
        }
        if (link.closest('[data-shell-sidebar]')) {
            closeMobileNav();
        }
        if (!kioskActive() || !shouldKioskFollowLink(event, link)) {
            return;
        }
        event.preventDefault();
        markKioskHistory();
        ensureNativeFullscreen().finally(() => {
            kioskVisit(link.href);
        });
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !kioskActive()) {
            return;
        }
        if (form.target && form.target !== '_self') {
            return;
        }
        if (form.dataset.fullPage === '1') {
            return;
        }

        let url;
        try {
            url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        } catch (e) {
            return;
        }
        if (!isInAppUrl(url)) {
            return;
        }

        event.preventDefault();
        const method = (form.getAttribute('method') || 'get').toUpperCase();
        const body = new FormData(form);
        if (event.submitter instanceof HTMLButtonElement && event.submitter.name) {
            body.set(event.submitter.name, event.submitter.value);
        }

        markKioskHistory();
        ensureNativeFullscreen().finally(() => {
            if (method === 'GET') {
                const next = new URL(url.href);
                new URLSearchParams(body).forEach((value, key) => next.searchParams.set(key, value));
                kioskVisit(next.href, { method: 'GET' });
                return;
            }
            kioskVisit(url.href, { method, body });
        });
    }, true);

    window.addEventListener('popstate', (event) => {
        if (event.state?.kiosk || kioskActive()) {
            kioskVisit(window.location.href, { skipHistory: true });
        }
    });

    document.addEventListener('fullscreenchange', () => {
        if (document.fullscreenElement) {
            persistFullscreen(true);
            document.documentElement.classList.add('is-app-fullscreen');
        }
        syncFullscreenButton();
    });
    document.addEventListener('webkitfullscreenchange', () => {
        if (document.webkitFullscreenElement) {
            persistFullscreen(true);
            document.documentElement.classList.add('is-app-fullscreen');
        }
        syncFullscreenButton();
    });
}

/**
 * Wire density, fullscreen, accordion and the mobile menu button.
 */
export function bindShell() {
    if (!document.querySelector('[data-shell-sidebar]')) {
        return;
    }

    syncDensityButton();
    syncFullscreenButton();
    restoreGroups();

    if (chromeBound) {
        return;
    }
    chromeBound = true;
    bindShellChrome();
}

window.AnnigemsShell = {
    setDensity,
    setFullscreen,
};
