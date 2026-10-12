/**
 * "Show 10 / 20 / 50 / 100 / All" under a list.
 *
 * Choosing a size reloads the same page with ?per_page=<size>, keeping every
 * filter already in the URL and returning to page 1, since page 3 of 100 per
 * page is not the same rows as page 3 of 10.
 */

/**
 * Wire every [data-per-page] select on the page.
 */
export function bindPerPage() {
    document.querySelectorAll('[data-per-page]').forEach((select) => {
        if (select.dataset.perPageBound === '1') {
            return;
        }
        select.dataset.perPageBound = '1';

        select.addEventListener('change', () => {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', select.value);
            url.searchParams.delete('page');
            window.location.assign(url.toString());
        });
    });
}
