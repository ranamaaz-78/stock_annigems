<?php

namespace App\Services;

use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\BranchAdminCredentialsMail;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * UserService
 *
 * SuperAdmin operations on users: create Branch Admins, list across branches,
 * activate/deactivate, and reset passwords. Role is always assigned server-side.
 *
 * created_by on a user is attribution only. Staff (and Admins) a former Admin
 * created remain fully visible and manageable by the replacement Admin because
 * every query here is scoped by branch, never by created_by = current user.
 *
 * Deactivation is a status change plus soft delete — never a hard delete —
 * so historical invoices, items, and audit logs keep a permanent user pointer.
 */
class UserService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected PasswordService $passwordService
    ) {}

    /**
     * Paginated cross-branch user list for SuperAdmin.
     *
     * Includes soft-deleted (deactivated) accounts. Never filters by created_by.
     *
     * @param  array{search?: string, branch?: string, role?: string, status?: string}  $filters
     */
    public function listForSuperAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query()->withTrashed()->with('branch')->orderBy('name');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by the branch's public uuid, never by the integer PK in the query string.
        if (! empty($filters['branch'])) {
            $query->whereHas('branch', function (Builder $q) use ($filters): void {
                $q->where('uuid', $filters['branch']);
            });
        }

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        $this->appendOnlyActiveAdminFlag($paginator->getCollection());

        return $paginator;
    }

    /**
     * Create a Branch Admin for an active branch.
     *
     * A branch may have more than one Admin so SuperAdmin can create the
     * replacement FIRST, then deactivate the outgoing Admin. The branch is
     * never left without an Admin during a planned replacement.
     *
     * Role is hard-set to ADMIN — never from input. Attribution (created_by /
     * created_by_role) is written by TracksUserAttribution from the
     * authenticated SuperAdmin.
     *
     * Returns [user, plainPassword] so the UI can show credentials once.
     *
     * @param  array<string, mixed>  $data  Validated StoreBranchAdminRequest (`branch` uuid) or internal `branch_id`
     * @return array{user: User, password: string}
     *
     * @throws ValidationException
     */
    public function createBranchAdmin(array $data, User $actor): array
    {
        $branch = $this->resolveBranchFromInput($data);

        if ($branch->status !== BranchStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'branch' => 'The selected branch is inactive. Activate it before assigning an admin.',
            ]);
        }

        $plainPassword = ! empty($data['password'])
            ? $data['password']
            : $this->passwordService->generateTemporaryPassword();

        $adminRole = Role::query()->where('name', UserRole::ADMIN->value)->firstOrFail();

        $user = DB::transaction(function () use ($data, $actor, $branch, $plainPassword, $adminRole) {
            $user = User::query()->create([
                'branch_id' => $branch->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $plainPassword,
                'role' => UserRole::ADMIN,
                'role_id' => $adminRole->id,
                'status' => UserStatus::ACTIVE,
                'must_change_password' => true,
            ]);

            $this->auditLogService->log(
                'user.created',
                $user,
                null,
                [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => UserRole::ADMIN->value,
                    'branch_id' => $branch->id,
                ],
                $actor
            );

            return $user;
        });

        // Optional email hook — works with MAIL_MAILER=log in local.
        if (! empty($data['send_credentials_email'])) {
            Mail::to($user->email)->send(new BranchAdminCredentialsMail($user, $plainPassword));
        }

        return ['user' => $user, 'password' => $plainPassword];
    }

    /**
     * Activate or deactivate a user account.
     *
     * Deactivate: status → inactive, then soft-delete. The row remains for
     * attribution and FKs; the user cannot log in.
     * Activate: restore the soft-deleted row, then status → active.
     * Never forceDeletes.
     */
    public function setStatus(User $user, UserStatus $status, User $actor): User
    {
        return DB::transaction(function () use ($user, $status, $actor) {
            $old = ['status' => $user->status?->value];

            if ($status === UserStatus::ACTIVE) {
                if ($user->trashed()) {
                    $user->restore();
                }

                $user->forceFill(['status' => $status])->save();
            } else {
                $user->forceFill(['status' => $status])->save();

                $this->auditLogService->log(
                    'user.deactivated',
                    $user,
                    $old,
                    ['status' => $status->value],
                    $actor
                );

                $this->invalidateUserSessions($user);

                // Soft-delete last so the audit row is written against a live model
                // instance. Login is blocked by both status and the SoftDeletes scope.
                $user->delete();

                return User::query()->withTrashed()->findOrFail($user->id);
            }

            $this->auditLogService->log(
                'user.activated',
                $user,
                $old,
                ['status' => $status->value],
                $actor
            );

            return $user->fresh();
        });
    }

    /**
     * Generate a new temporary password, force change on next login, return plain text once.
     *
     * @return array{user: User, password: string}
     */
    public function resetPassword(User $user, User $actor): array
    {
        $plainPassword = $this->passwordService->generateTemporaryPassword();

        $user = DB::transaction(function () use ($user, $actor, $plainPassword) {
            $user->forceFill([
                'password' => $plainPassword,
                'must_change_password' => true,
            ])->save();

            $this->auditLogService->log(
                'user.password_reset_by_admin',
                $user,
                null,
                ['must_change_password' => true],
                $actor
            );

            return User::query()->withTrashed()->findOrFail($user->id);
        });

        return ['user' => $user, 'password' => $plainPassword];
    }

    /**
     * Invalidate all active database sessions for a user (immediate logout).
     */
    public function invalidateUserSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    /**
     * Load a user (including deactivated) for the SuperAdmin detail screen.
     */
    public function findForDetail(User $user): User
    {
        return User::query()
            ->withTrashed()
            ->with(['branch', 'creator', 'updater'])
            ->findOrFail($user->id);
    }

    /**
     * Audit log entries where this user is the affected model.
     *
     * @return Collection<int, AuditLog>
     */
    public function auditEntries(User $user, int $limit = 50): Collection
    {
        return AuditLog::query()
            ->with('user')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Whether this user is the only currently active Admin of their branch.
     *
     * Used to show the SuperAdmin warning before leaving a branch unmanaged.
     */
    public function isOnlyActiveAdmin(User $user): bool
    {
        if ($user->role !== UserRole::ADMIN || $user->branch_id === null) {
            return false;
        }

        if ($user->status !== UserStatus::ACTIVE || $user->trashed()) {
            return false;
        }

        $activeAdminCount = User::query()
            ->where('branch_id', $user->branch_id)
            ->where('role', UserRole::ADMIN)
            ->where('status', UserStatus::ACTIVE)
            ->count();

        return $activeAdminCount <= 1;
    }

    /**
     * Stamp is_only_active_admin on each row for the users-table SweetAlert.
     *
     * @param  Collection<int, User>  $users
     */
    protected function appendOnlyActiveAdminFlag(Collection $users): void
    {
        $branchIds = $users
            ->filter(fn (User $user): bool => $user->role === UserRole::ADMIN && $user->status === UserStatus::ACTIVE && $user->branch_id)
            ->pluck('branch_id')
            ->unique()
            ->all();

        $counts = [];
        if ($branchIds !== []) {
            $counts = User::query()
                ->whereIn('branch_id', $branchIds)
                ->where('role', UserRole::ADMIN)
                ->where('status', UserStatus::ACTIVE)
                ->selectRaw('branch_id, count(*) as aggregate')
                ->groupBy('branch_id')
                ->pluck('aggregate', 'branch_id')
                ->all();
        }

        foreach ($users as $user) {
            $user->setAttribute(
                'is_only_active_admin',
                $user->role === UserRole::ADMIN
                    && $user->status === UserStatus::ACTIVE
                    && (int) ($counts[$user->branch_id] ?? 0) <= 1
            );
        }
    }

    /**
     * Resolve a branch from HTTP (public uuid in `branch`) or internal callers (`branch_id`).
     *
     * Foreign keys always use the integer id; the uuid is lookup-only.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveBranchFromInput(array $data): Branch
    {
        if (! empty($data['branch'])) {
            return Branch::query()->where('uuid', $data['branch'])->firstOrFail();
        }

        return Branch::query()->findOrFail($data['branch_id']);
    }
}
