<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreBranchAdminRequest;
use App\Models\Branch;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * UserController
 *
 * SuperAdmin users list, Branch Admin creation, user detail, activate/deactivate, password reset.
 */
class UserController extends Controller
{
    public function __construct(
        protected UserService $userService
    ) {}

    /**
     * Cross-branch users table.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->only(['search', 'branch', 'role', 'status']);

        return view('superadmin.users.index', [
            'users' => $this->userService->listForSuperAdmin($filters),
            'branches' => Branch::query()->orderBy('name')->get(['uuid', 'name']),
            'roles' => collect(UserRole::cases())->mapWithKeys(fn (UserRole $r) => [$r->value => $r->label()]),
            'filters' => $filters,
        ]);
    }

    /**
     * Show create Branch Admin form.
     */
    public function createAdmin(): View
    {
        $this->authorize('createBranchAdmin', User::class);

        // All active branches — SuperAdmin may create a replacement Admin
        // while the outgoing Admin is still active, then deactivate the old one.
        $branches = Branch::query()
            ->where('status', BranchStatus::ACTIVE)
            ->orderBy('name')
            ->get(['uuid', 'name', 'code']);

        return view('superadmin.users.create-admin', compact('branches'));
    }

    /**
     * Persist Branch Admin and flash one-time credentials for SweetAlert.
     */
    public function storeAdmin(StoreBranchAdminRequest $request): RedirectResponse
    {
        $this->authorize('createBranchAdmin', User::class);

        $result = $this->userService->createBranchAdmin($request->validated(), $request->user());

        return redirect()
            ->route('superadmin.users.index')
            ->with('success', 'Branch Admin created successfully.')
            ->with('generated_credentials', [
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'password' => $result['password'],
            ]);
    }

    /**
     * Show a user (including deactivated) with attribution and audit history.
     */
    public function show(User $user): View
    {
        $this->authorize('view', $user);

        $user = $this->userService->findForDetail($user);

        return view('superadmin.users.show', [
            'user' => $user,
            'auditLogs' => $this->userService->auditEntries($user),
            'isOnlyActiveAdmin' => $this->userService->isOnlyActiveAdmin($user),
        ]);
    }

    /**
     * Deactivate a user account.
     */
    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('toggleStatus', $user);

        $this->userService->setStatus($user, UserStatus::INACTIVE, $request->user());

        return back()->with('success', 'User deactivated.');
    }

    /**
     * Activate a user account.
     */
    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('toggleStatus', $user);

        $this->userService->setStatus($user, UserStatus::ACTIVE, $request->user());

        return back()->with('success', 'User activated.');
    }

    /**
     * Reset password and show the new temporary password once.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('resetPassword', $user);

        $result = $this->userService->resetPassword($user, $request->user());

        return back()
            ->with('success', 'Password reset. Share the new temporary password securely.')
            ->with('generated_credentials', [
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'password' => $result['password'],
            ]);
    }
}
