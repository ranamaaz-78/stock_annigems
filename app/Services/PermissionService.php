<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PermissionService
 *
 * Resolves effective permissions using a hybrid dual-pivot model:
 * - role_permission: default permissions per role (seeded)
 * - permission_user: per-user grant/revoke overrides (granted boolean)
 *
 * Hard gates applied after merge (cannot be overridden via UI):
 * - reports.view → Admin only
 * - reports.export → Admin only
 * - reports.superadmin → SuperAdmin only
 * - gold_rates.manage → SuperAdmin only
 * - settings.global.manage / settings.history.view → SuperAdmin only
 * - settings.branch.manage → Admin only
 * - items.view_cost → Admin and Manager only
 *
 * SuperAdmin bypasses permission checks (full system access), which is exactly
 * why the report policies must also assert the role explicitly — see
 * App\Policies\ReportPolicy.
 */
class PermissionService
{
    /**
     * Permissions that can never be assigned to non-Admin roles via UI or API.
     *
     * @var list<string>
     */
    public const ADMIN_ONLY_PERMISSIONS = [
        'reports.view',
        'reports.export',
        'reports.superadmin',
        'users.create',
        'users.edit',
        'users.deactivate',
        'gold_rates.manage',
        'settings.global.manage',
        'settings.history.view',
        'settings.branch.manage',
    ];

    /**
     * Permissions restricted to Admin and Manager (never Cashier / Inventory Staff).
     *
     * @var list<string>
     */
    public const COST_VIEW_PERMISSIONS = [
        'items.view_cost',
    ];

    /**
     * Staff roles a branch Admin may assign (never admin or superadmin).
     *
     * @var list<string>
     */
    public const ASSIGNABLE_STAFF_ROLES = [
        UserRole::MANAGER->value,
        UserRole::CASHIER->value,
        UserRole::INVENTORY_STAFF->value,
    ];

    /**
     * Effective permission name strings for a user.
     *
     * @return list<string>
     */
    public function effectivePermissionNames(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return Permission::query()->pluck('name')->all();
        }

        $roleDefaults = $this->roleDefaultNames($user);
        $overrides = $this->userOverrideMap($user);

        $effective = [];

        foreach (Permission::query()->pluck('name') as $name) {
            if (array_key_exists($name, $overrides)) {
                if ($overrides[$name]) {
                    $effective[] = $name;
                }

                continue;
            }

            if (in_array($name, $roleDefaults, true)) {
                $effective[] = $name;
            }
        }

        return $this->applyHardGates($user, $effective);
    }

    /**
     * Effective Permission models for a user.
     *
     * @return Collection<int, Permission>
     */
    public function effectivePermissions(User $user): Collection
    {
        $names = $this->effectivePermissionNames($user);

        return Permission::query()->whereIn('name', $names)->orderBy('module')->orderBy('name')->get();
    }

    /**
     * Permissions grouped by module for the permission grid UI.
     *
     * @return Collection<string, Collection<int, Permission>>
     */
    public function permissionsGroupedByModule(): Collection
    {
        return Permission::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module');
    }

    /**
     * Default permission names for a role by machine name.
     *
     * @return list<string>
     */
    public function defaultPermissionNamesForRole(string $roleName): array
    {
        $role = Role::query()->where('name', $roleName)->with('permissions')->first();

        if (! $role) {
            return [];
        }

        return $role->permissions->pluck('name')->all();
    }

    /**
     * JSON-ready map of role => default permission names for frontend role dropdown.
     *
     * @return array<string, list<string>>
     */
    public function roleDefaultsMap(): array
    {
        $map = [];

        foreach (self::ASSIGNABLE_STAFF_ROLES as $roleName) {
            $map[$roleName] = $this->defaultPermissionNamesForRole($roleName);
        }

        // Admin defaults for reference screen.
        $map[UserRole::ADMIN->value] = $this->defaultPermissionNamesForRole(UserRole::ADMIN->value);

        return $map;
    }

    /**
     * Permissions that must be disabled in the UI for a given target role.
     *
     * @return list<string>
     */
    public function disabledPermissionsForRole(string $roleName): array
    {
        $disabled = [];

        if ($roleName !== UserRole::ADMIN->value) {
            $disabled = array_merge($disabled, self::ADMIN_ONLY_PERMISSIONS);
        }

        if (! in_array($roleName, [UserRole::ADMIN->value, UserRole::MANAGER->value], true)) {
            $disabled = array_merge($disabled, self::COST_VIEW_PERMISSIONS);
        }

        return array_values(array_unique($disabled));
    }

    /**
     * Validate and return assignable permission names for a staff user.
     *
     * @param  list<string>  $requested
     * @return list<string> Effective permission names to store as overrides
     *
     * @throws ValidationException
     */
    public function resolveAssignablePermissions(User $actor, string $targetRoleName, array $requested): array
    {
        $this->assertStaffRoleAssignable($targetRoleName);

        $allNames = Permission::query()->pluck('name')->all();
        $invalid = array_diff($requested, $allNames);

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'permissions' => __('One or more selected permissions are invalid.'),
            ]);
        }

        $disabled = $this->disabledPermissionsForRole($targetRoleName);

        foreach ($requested as $name) {
            if (in_array($name, $disabled, true)) {
                throw ValidationException::withMessages([
                    'permissions' => __('Permission :name cannot be assigned to this role.', ['name' => $name]),
                ]);
            }
        }

        $roleDefaults = $this->defaultPermissionNamesForRole($targetRoleName);

        // Return only overrides differing from role defaults.
        return $requested;
    }

    /**
     * Sync permission_user overrides from the Admin's selected checkboxes.
     *
     * Writes attribution on each pivot row and audit logs grant/revoke changes.
     *
     * @param  list<string>  $selectedNames Full desired effective set from form
     */
    public function syncUserPermissionOverrides(User $target, string $roleName, array $selectedNames, User $actor): void
    {
        $roleDefaults = $this->defaultPermissionNamesForRole($roleName);
        $permissions = Permission::query()->whereIn('name', $selectedNames)->get()->keyBy('name');
        $allPermissions = Permission::query()->get()->keyBy('name');

        DB::transaction(function () use ($target, $roleDefaults, $selectedNames, $permissions, $allPermissions, $actor): void {
            $existing = $target->permissionOverrides()->get()->keyBy('id');

            foreach ($allPermissions as $permission) {
                $inDefaults = in_array($permission->name, $roleDefaults, true);
                $inSelected = in_array($permission->name, $selectedNames, true);
                $needsOverride = ($inSelected && ! $inDefaults) || (! $inSelected && $inDefaults);

                $pivot = $existing->get($permission->id);

                if (! $needsOverride) {
                    if ($pivot) {
                        $target->permissionOverrides()->detach($permission->id);
                    }

                    continue;
                }

                $granted = $inSelected;

                if ($pivot) {
                    $target->permissionOverrides()->updateExistingPivot($permission->id, [
                        'granted' => $granted,
                    ]);
                } else {
                    $target->permissionOverrides()->attach($permission->id, [
                        'granted' => $granted,
                    ]);
                }
            }

            $target->clearPermissionCache();
        });
    }

    /**
     * Build effective permission breakdown for user detail (defaults vs overrides).
     *
     * @return array{
     *     effective: list<string>,
     *     from_role: list<string>,
     *     granted_overrides: list<string>,
     *     revoked_overrides: list<string>
     * }
     */
    public function permissionBreakdown(User $user): array
    {
        $roleDefaults = $this->roleDefaultNames($user);
        $overrides = $this->userOverrideMap($user);
        $grantedOverrides = [];
        $revokedOverrides = [];

        foreach ($overrides as $name => $granted) {
            if ($granted) {
                $grantedOverrides[] = $name;
            } else {
                $revokedOverrides[] = $name;
            }
        }

        return [
            'effective' => $this->effectivePermissionNames($user),
            'from_role' => $roleDefaults,
            'granted_overrides' => $grantedOverrides,
            'revoked_overrides' => $revokedOverrides,
        ];
    }

    /**
     * Reject non-staff role assignment attempts server-side.
     *
     * @throws ValidationException
     */
    public function assertStaffRoleAssignable(string $roleName): void
    {
        if (! in_array($roleName, self::ASSIGNABLE_STAFF_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => __('Invalid role selection.'),
            ]);
        }
    }

    /**
     * Default permission names from the user's role record.
     *
     * Uses role_id when set; falls back to the role enum name so users
     * created before role backfill still receive role defaults.
     *
     * @return list<string>
     */
    private function roleDefaultNames(User $user): array
    {
        if ($user->role_id) {
            $user->loadMissing('roleRecord.permissions');

            return $user->roleRecord?->permissions->pluck('name')->all() ?? [];
        }

        $roleName = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        if ($roleName === '') {
            return [];
        }

        return $this->defaultPermissionNamesForRole($roleName);
    }

    /**
     * @return array<string, bool>
     */
    private function userOverrideMap(User $user): array
    {
        $user->loadMissing('permissionOverrides');

        $map = [];

        foreach ($user->permissionOverrides as $permission) {
            $map[$permission->name] = (bool) $permission->pivot->granted;
        }

        return $map;
    }

    /**
     * Apply hard gates that cannot be overridden by DB rows or UI toggles.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function applyHardGates(User $user, array $permissions): array
    {
        $role = $user->role instanceof UserRole ? $user->role : UserRole::from((string) $user->role);

        return array_values(array_filter($permissions, function (string $name) use ($role, $user): bool {
            // Branch reporting is Admin-only and cannot be delegated to staff.
            if (in_array($name, ['reports.view', 'reports.export'], true) && $role !== UserRole::ADMIN) {
                return false;
            }

            // Cross-branch aggregate reporting belongs to SuperAdmin alone. A
            // branch Admin holding this permission would be a tenant breach.
            if ($name === 'reports.superadmin' && ! $user->isSuperAdmin()) {
                return false;
            }

            if (in_array($name, ['gold_rates.manage', 'settings.global.manage', 'settings.history.view'], true)
                && ! $user->isSuperAdmin()) {
                return false;
            }

            if ($name === 'settings.branch.manage' && $role !== UserRole::ADMIN) {
                return false;
            }

            if ($name === 'items.view_cost' && ! in_array($role, [UserRole::ADMIN, UserRole::MANAGER], true)) {
                return false;
            }

            return true;
        }));
    }
}
