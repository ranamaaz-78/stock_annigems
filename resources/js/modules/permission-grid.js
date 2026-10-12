/**
 * Permission grid — role dropdown pre-fills default permission checkboxes.
 */

/**
 * Bind permission grid behaviour to staff user forms.
 */
export function bindPermissionGrids() {
    document.querySelectorAll('[data-permission-grid]').forEach((grid) => {
        const defaultsMap = JSON.parse(grid.dataset.roleDefaults || '{}');
        const roleSelect = document.querySelector('[data-role-select]');

        if (!roleSelect) {
            return;
        }

        const checkboxes = () => grid.querySelectorAll('.permission-grid__checkbox');

        const applyRoleDefaults = (roleName) => {
            const defaults = defaultsMap[roleName] || [];
            const disabled = getDisabledForRole(roleName);

            checkboxes().forEach((box) => {
                const name = box.dataset.permissionName;
                const isDisabled = disabled.includes(name);
                box.disabled = isDisabled;
                box.checked = defaults.includes(name) && !isDisabled;
            });
        };

        roleSelect.addEventListener('change', () => {
            applyRoleDefaults(roleSelect.value);
        });

        // Initial sync when role changes via old() on validation error.
        if (roleSelect.value) {
            applyRoleDefaults(roleSelect.value);
        }
    });
}

/**
 * Permissions disabled per role (mirrors PermissionService server rules).
 *
 * @param {string} roleName
 * @returns {string[]}
 */
function getDisabledForRole(roleName) {
    const adminOnly = ['reports.view', 'users.create', 'users.edit', 'users.deactivate'];
    const costOnly = ['items.view_cost'];

    let disabled = [];

    if (roleName !== 'admin') {
        disabled = disabled.concat(adminOnly);
    }

    if (roleName !== 'admin' && roleName !== 'manager') {
        disabled = disabled.concat(costOnly);
    }

    return disabled;
}
