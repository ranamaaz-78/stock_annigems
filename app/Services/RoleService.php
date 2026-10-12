<?php

namespace App\Services;

use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * RoleService
 *
 * Read-only access to system roles and their default permissions for the
 * Roles & Permissions reference screen. Branch Admins cannot edit roles.
 */
class RoleService
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    /**
     * All system roles with default permissions grouped by module.
     *
     * @return Collection<int, Role>
     */
    public function rolesWithDefaults(): Collection
    {
        return Role::query()
            ->where('is_system', true)
            ->whereIn('name', ['admin', 'manager', 'cashier', 'inventory_staff'])
            ->with(['permissions' => fn ($q) => $q->orderBy('module')->orderBy('name')])
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Staff roles available in the create/edit user dropdown.
     *
     * @return Collection<int, Role>
     */
    public function assignableStaffRoles(): Collection
    {
        return Role::query()
            ->whereIn('name', PermissionService::ASSIGNABLE_STAFF_ROLES)
            ->orderBy('display_name')
            ->get();
    }
}
