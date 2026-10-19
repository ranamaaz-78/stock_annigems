/**
 * Translation for browser scripts.
 *
 * Texts are written in English and looked up in window.AppI18n.messages,
 * which each layout fills from lang/{locale}/js.php (see trans_js()). A text
 * with no translation shows in English, so nothing ever renders blank.
 *
 * Placeholders use Laravel's style: a text "Delete :name?" with { name: 'AMATISTA' }.
 */

/**
 * Translate one text.
 *
 * @param {string} text English text, exactly as listed in lang/es/js.php
 * @param {Record<string, string|number>} [replace]
 * @returns {string}
 */
export function t(text, replace = {}) {
    const messages = window.AppI18n?.messages || {};
    let out = Object.prototype.hasOwnProperty.call(messages, text) ? messages[text] : text;

    // Longest names first, so :name never eats the start of :names.
    Object.entries(replace).sort(([a], [b]) => b.length - a.length).forEach(([key, value]) => {
        out = out.split(`:${key}`).join(String(value));
    });

    return out;
}

/**
 * The page's language code ("es" or "en").
 *
 * @returns {string}
 */
export function locale() {
    return window.AppI18n?.locale || document.documentElement.lang || 'es';
}

window.AnnigemsI18n = { t, locale };
