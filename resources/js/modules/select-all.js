/**
 * "Select all" checkbox for a column of row checkboxes.
 *
 * Markup: a header checkbox with data-select-all="<name>" inside the same
 * form as the row checkboxes named <name> (e.g. uuids[]). Ticking it ticks
 * every row on the page; unticking clears them. When only some rows are
 * ticked it shows the half-filled (indeterminate) state, and it follows the
 * rows as they are ticked one by one.
 */

/**
 * Wire every [data-select-all] checkbox on the page.
 */
export function bindSelectAll() {
    document.querySelectorAll('[data-select-all]').forEach((master) => {
        if (master.dataset.selectAllBound === '1') {
            return;
        }
        master.dataset.selectAllBound = '1';

        const scope = master.closest('form') || document;
        const name = master.dataset.selectAll;
        const rows = () => [...scope.querySelectorAll('input[type="checkbox"]')]
            .filter((box) => box !== master && box.name === name && !box.disabled);

        /**
         * Match the header box to what the rows currently show.
         */
        const sync = () => {
            const boxes = rows();
            const ticked = boxes.filter((box) => box.checked).length;

            master.checked = boxes.length > 0 && ticked === boxes.length;
            master.indeterminate = ticked > 0 && ticked < boxes.length;
        };

        master.addEventListener('change', () => {
            rows().forEach((box) => {
                box.checked = master.checked;
            });
            sync();
        });

        scope.addEventListener('change', (event) => {
            if (event.target !== master && event.target.name === name) {
                sync();
            }
        });

        sync();
    });
}
