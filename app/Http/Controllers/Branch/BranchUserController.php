<?php

namespace App\Http\Controllers\Branch;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreBranchStaffRequest;
use App\Http\Requests\Branch\UpdateBranchStaffRequest;
use App\Models\User;
use App\Services\BranchStaffService;
use App\Services\PermissionService;
use App\Services\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BranchUserController
 *
 * Branch Admin staff management: list, create, edit, detail, activate/deactivate,
 * and password reset — all scoped to the acting Admin's branch.
 */
class BranchUserController extends Controller
{
    public function __construct(
        protected BranchStaffService $branchStaffService,
        protected PermissionService $permissionService,
        protected RoleService $roleService
    ) {}

    /**
     * Staff users table for this branch.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->only(['search', 'role', 'status']);

        return view('branch.users.index', [
            'users' => $this->branchStaffService->listForBranch($request->user(), $filters),
            'roles' => $this->roleService->assignableStaffRoles(),
            'filters' => $filters,
        ]);
    }

    /**
     * Create staff form.
     */
    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('branch.users.create', $this->formSharedData());
    }

    /**
     * Persist new staff member.
     */
    public function store(StoreBranchStaffRequest $request): RedirectResponse
    {
        $result = $this->branchStaffService->createStaff($request->validated(), $request->user());

        return redirect()
            ->route('branch.users.index')
            ->with('success', __('User created successfully.'))
            ->with('generated_credentials', [
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'password' => $result['password'],
            ]);
    }

    /**
     * Staff user detail.
     */
    public function show(User $user): View
    {
        $this->authorize('view', $user);

        $staff = $this->branchStaffService->findForDetail($user);

        return view('branch.users.show', [
            'user' => $staff,
            'permissionBreakdown' => $this->permissionService->permissionBreakdown($staff),
            'auditEntries' => $this->branchStaffService->auditEntries($staff),
        ]);
    }

    /**
     * Edit staff form.
     */
    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        $staff = $this->branchStaffService->findForDetail($user);

        return view('branch.users.edit', array_merge(
            $this->formSharedData($staff),
            ['user' => $staff]
        ));
    }

    /**
     * Update staff member.
     */
    public function update(UpdateBranchStaffRequest $request, User $user): RedirectResponse
    {
        $this->branchStaffService->updateStaff($user, $request->validated(), $request->user());

        return redirect()
            ->route('branch.users.show', $user)
            ->with('success', __('User updated successfully.'));
    }

    /**
     * Deactivate a staff member.
     */
    public function deactivate(User $user): RedirectResponse
    {
        $this->authorize('toggleStatus', $user);

        $this->branchStaffService->setStatus($user, UserStatus::INACTIVE, request()->user());

        return redirect()
            ->route('branch.users.index')
            ->with('success', __('User deactivated successfully.'));
    }

    /**
     * Reactivate a staff member.
     */
    public function activate(User $user): RedirectResponse
    {
        $this->authorize('toggleStatus', $user);

        $this->branchStaffService->setStatus($user, UserStatus::ACTIVE, request()->user());

        return redirect()
            ->route('branch.users.show', $user)
            ->with('success', __('User activated successfully.'));
    }

    /**
     * Reset staff password and flash one-time credentials.
     */
    public function resetPassword(User $user): RedirectResponse
    {
        $this->authorize('resetPassword', $user);

        $result = $this->branchStaffService->resetPassword($user, request()->user());

        return redirect()
            ->route('branch.users.show', $user)
            ->with('success', __('Password reset successfully.'))
            ->with('generated_credentials', [
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'password' => $result['password'],
            ]);
    }

    /**
     * Shared form data for create/edit permission grid.
     *
     * @return array<string, mixed>
     */
    protected function formSharedData(?User $user = null): array
    {
        $roleName = old('role', $user?->role->value ?? PermissionService::ASSIGNABLE_STAFF_ROLES[0]);
        $defaults = $this->permissionService->defaultPermissionNamesForRole($roleName);
        $effective = $user ? $this->permissionService->effectivePermissionNames($user) : $defaults;

        return [
            'staffRoles' => $this->roleService->assignableStaffRoles(),
            'permissionsByModule' => $this->permissionService->permissionsGroupedByModule(),
            'roleDefaultsMap' => $this->permissionService->roleDefaultsMap(),
            'selectedPermissions' => old('permissions', $user ? $effective : $defaults),
            'disabledPermissions' => $this->permissionService->disabledPermissionsForRole($roleName),
            'initialRole' => $roleName,
        ];
    }
}
