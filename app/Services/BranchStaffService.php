<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BranchStaffService
 *
 * Branch-scoped staff management for branch Admins: create/update Manager,
 * Cashier, and Inventory Staff within their own branch only.
 *
 * branch_id is always derived from the acting Admin — never from request input.
 * Role is validated server-side; Admin/SuperAdmin assignment is rejected.
 */
class BranchStaffService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected PasswordService $passwordService,
        protected PermissionService $permissionService,
        protected UserService $userService
    ) {}

    /**
     * Paginated staff list for the acting Admin's branch (includes deactivated).
     *
     * @param  array{search?: string, role?: string, status?: string}  $filters
     */
    public function listForBranch(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query()
            ->withTrashed()
            ->with('roleRecord')
            ->where('branch_id', $actor->branch_id)
            ->where('role', '!=', UserRole::ADMIN)
            ->orderBy('name');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Create a staff user for the Admin's branch.
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, password: string}
     */
    public function createStaff(array $data, User $actor): array
    {
        $roleName = (string) $data['role'];
        $this->permissionService->assertStaffRoleAssignable($roleName);

        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $permissions = $data['permissions'] ?? [];
        $this->permissionService->resolveAssignablePermissions($actor, $roleName, $permissions);

        $plainPassword = ! empty($data['password'])
            ? $data['password']
            : $this->passwordService->generateTemporaryPassword();

        $user = DB::transaction(function () use ($data, $actor, $role, $roleName, $permissions, $plainPassword) {
            $user = User::query()->create([
                'branch_id' => $actor->branch_id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $plainPassword,
                'role' => UserRole::from($roleName),
                'role_id' => $role->id,
                'status' => UserStatus::from($data['status'] ?? UserStatus::ACTIVE->value),
                'must_change_password' => true,
            ]);

            $this->permissionService->syncUserPermissionOverrides($user, $roleName, $permissions, $actor);

            $this->auditLogService->log(
                'user.created',
                $user,
                null,
                [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $roleName,
                    'branch_id' => $actor->branch_id,
                ],
                $actor
            );

            return $user;
        });

        return ['user' => $user->fresh(['roleRecord']), 'password' => $plainPassword];
    }

    /**
     * Update staff profile, role, status, and permission overrides.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateStaff(User $target, array $data, User $actor): User
    {
        if ($target->id === $actor->id) {
            throw ValidationException::withMessages([
                'role' => 'You cannot change your own role or permissions here. Use My Profile.',
            ]);
        }

        if ($target->branch_id !== $actor->branch_id) {
            abort(403);
        }

        if ($target->role === UserRole::ADMIN) {
            throw ValidationException::withMessages([
                'role' => 'Branch Admins cannot modify other Admin accounts.',
            ]);
        }

        $roleName = (string) ($data['role'] ?? $target->role->value);
        $this->permissionService->assertStaffRoleAssignable($roleName);

        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $permissions = $data['permissions'] ?? [];
        $this->permissionService->resolveAssignablePermissions($actor, $roleName, $permissions);

        return DB::transaction(function () use ($target, $data, $actor, $role, $roleName, $permissions) {
            $old = [
                'name' => $target->name,
                'email' => $target->email,
                'phone' => $target->phone,
                'role' => $target->role->value,
                'status' => $target->status->value,
            ];

            $target->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => UserRole::from($roleName),
                'role_id' => $role->id,
                'status' => UserStatus::from($data['status'] ?? $target->status->value),
            ])->save();

            $this->permissionService->syncUserPermissionOverrides($target, $roleName, $permissions, $actor);

            $this->auditLogService->log(
                'user.updated',
                $target,
                $old,
                [
                    'name' => $target->name,
                    'email' => $target->email,
                    'phone' => $target->phone,
                    'role' => $roleName,
                    'status' => $target->status->value,
                ],
                $actor
            );

            return $target->fresh(['roleRecord', 'permissionOverrides']);
        });
    }

    /**
     * Activate or deactivate a staff member; invalidates sessions on deactivate.
     */
    public function setStatus(User $target, UserStatus $status, User $actor): User
    {
        if ($target->id === $actor->id) {
            throw ValidationException::withMessages([
                'status' => 'You cannot change your own status.',
            ]);
        }

        if ($target->branch_id !== $actor->branch_id || $target->role === UserRole::ADMIN) {
            abort(403);
        }

        $user = $this->userService->setStatus($target, $status, $actor);

        if ($status === UserStatus::INACTIVE) {
            $this->userService->invalidateUserSessions($target);
        }

        return $user;
    }

    /**
     * Reset a staff member's password.
     *
     * @return array{user: User, password: string}
     */
    public function resetPassword(User $target, User $actor): array
    {
        if ($target->id === $actor->id) {
            throw ValidationException::withMessages([
                'password' => 'Use My Profile to change your own password.',
            ]);
        }

        if ($target->branch_id !== $actor->branch_id || $target->role === UserRole::ADMIN) {
            abort(403);
        }

        return $this->userService->resetPassword($target, $actor);
    }

    /**
     * Load staff user for detail screen.
     */
    public function findForDetail(User $target): User
    {
        return User::query()
            ->withTrashed()
            ->with(['branch', 'roleRecord', 'creator', 'updater', 'permissionOverrides'])
            ->findOrFail($target->id);
    }

    /**
     * Audit entries for a staff user.
     *
     * @return Collection<int, AuditLog>
     */
    public function auditEntries(User $target, int $limit = 50): Collection
    {
        return $this->userService->auditEntries($target, $limit);
    }

    /**
     * @param  Builder<User>  $query
     * @param  array{search?: string, role?: string, status?: string}  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
    }
}
