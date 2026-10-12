<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\View\View;

/**
 * RoleReferenceController
 *
 * Read-only reference of system roles and their default permissions.
 * Admin-only screen; roles themselves are not editable.
 */
class RoleReferenceController extends Controller
{
    public function __construct(
        protected RoleService $roleService
    ) {}

    /**
     * Roles and default permissions matrix.
     */
    public function index(): View
    {
        $this->authorize('viewRoleReference', User::class);

        return view('branch.roles.index', [
            'roles' => $this->roleService->rolesWithDefaults(),
        ]);
    }
}
