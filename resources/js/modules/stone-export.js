/**
 * Excel export on the stone list.
 *
 * One hidden POST form (#stone-export-form) carries the page's filters. The
 * buttons add what is ticked to it and submit it, so the file download never
 * leaves the page:
 *   [data-export-all]                   everything the filters show
 *   [data-export-selected="categories"] ticked folder tiles
 *   [data-export-selected="uuids"]      ticked lots of the table
 */

/**
 * Submit the export form with the given field values.
 *
 * @param {HTMLFormElement} form
 * @param {string} field   Input name, e.g. "uuids[]"
 * @param {string[]} values
 */
function submitExport(form, field, values) {
    form.querySelectorAll('[data-export-extra]').forEach((input) => input.remove());

    values.forEach((value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = field;
        input.value = value;
        input.dataset.exportExtra = '1';
        form.appendChild(input);
    });

    form.submit();
}

/**
 * Wire the export buttons, the folder tile checkboxes and "select all".
 */
export function bindStoneExport() {
    const form = document.getElementById('stone-export-form');
    if (!form || form.dataset.bound === '1') {
        return;
    }
    form.dataset.bound = '1';

    const folderBoxes = () => [...document.querySelectorAll('[data-export-folder]')];
    const lotBoxes = () => [...document.querySelectorAll('input[name="uuids[]"]')];

    const sources = {
        categories: () => folderBoxes().filter((box) => box.checked).map((box) => box.value),
        uuids: () => lotBoxes().filter((box) => box.checked).map((box) => box.value),
    };
    const fieldNames = { categories: 'categories[]', uuids: 'uuids[]' };

    // A button is on only while something is ticked for it.
    const sync = () => {
        document.querySelectorAll('[data-export-selected]').forEach((button) => {
            button.disabled = sources[button.dataset.exportSelected]().length === 0;
        });

        const all = document.querySelector('[data-export-folder-all]');
        if (all) {
            const boxes = folderBoxes();
            const ticked = boxes.filter((box) => box.checked).length;
            all.checked = boxes.length > 0 && ticked === boxes.length;
            all.indeterminate = ticked > 0 && ticked < boxes.length;
        }
    };

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-export-folder-all]')) {
            folderBoxes().forEach((box) => {
                box.checked = event.target.checked;
            });
        }
        sync();
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-export-all]')) {
            submitExport(form, 'noop', []);

            return;
        }

        const selected = event.target.closest('[data-export-selected]');
        if (selected) {
            const kind = selected.dataset.exportSelected;
            const values = sources[kind]();
            if (values.length > 0) {
                submitExport(form, fieldNames[kind], values);
            }
        }
    });

    sync();
}
