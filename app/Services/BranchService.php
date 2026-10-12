<?php

namespace App\Services;

use App\Enums\BranchStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BranchService
 *
 * Business logic for branch creation, updating, and activation/deactivation.
 * Branches are soft-deleted only via status changes in this module — never
 * hard deleted. Every mutation writes an audit log entry.
 */
class BranchService
{
    public function __construct(
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Paginated branch list with optional search and filters.
     *
     * Scoped by nothing except the requested filters — never by created_by.
     * Data belongs to the branch, not the SuperAdmin who registered it.
     *
     * @param  array{search?: string, status?: string, city?: string}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->with(['activeAdmins', 'creator'])
            ->withCount(['users' => fn ($q) => $q->withTrashed()])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Build a filtered query for branches (also used by the dashboard).
     *
     * @param  array{search?: string, status?: string, city?: string}  $filters
     */
    public function filteredQuery(array $filters = []): Builder
    {
        $query = Branch::query();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        return $query;
    }

    /**
     * Distinct city values for filter dropdowns.
     *
     * @return list<string>
     */
    public function cities(): array
    {
        return Branch::query()
            ->whereNotNull('city')
            ->distinct()
            ->orderBy('city')
            ->pluck('city')
            ->all();
    }

    /**
     * Create a new branch.
     *
     * Wrapped in a transaction because creation also writes an audit log entry.
     *
     * @param  array<string, mixed>  $data  Validated data from StoreBranchRequest
     * @param  User  $actor  SuperAdmin creating the branch
     */
    public function create(array $data, User $actor): Branch
    {
        return DB::transaction(function () use ($data, $actor) {
            $branch = Branch::query()->create([
                'name' => $data['name'],
                'code' => strtoupper($data['code']),
                'address' => $data['address'],
                'city' => $data['city'],
                'contact_number' => $data['contact_number'],
                'email' => $data['email'] ?? null,
                'status' => $data['status'] ?? BranchStatus::ACTIVE->value,
            ]);

            $this->auditLogService->log(
                'branch.created',
                $branch,
                null,
                $branch->toArray(),
                $actor
            );

            return $branch;
        });
    }

    /**
     * Update an existing branch.
     *
     * @param  array<string, mixed>  $data  Validated data from UpdateBranchRequest
     */
    public function update(Branch $branch, array $data, User $actor): Branch
    {
        return DB::transaction(function () use ($branch, $data, $actor) {
            $old = $branch->toArray();

            $branch->fill([
                'name' => $data['name'],
                'code' => strtoupper($data['code']),
                'address' => $data['address'],
                'city' => $data['city'],
                'contact_number' => $data['contact_number'],
                'email' => $data['email'] ?? null,
                'status' => $data['status'],
            ])->save();

            $this->auditLogService->log(
                'branch.updated',
                $branch,
                $old,
                $branch->fresh()->toArray(),
                $actor
            );

            return $branch->fresh();
        });
    }

    /**
     * Update the details printed on this branch's documents.
     *
     * Used by the branch Settings screen. Code and status stay on the
     * SuperAdmin branch form so an Admin cannot rename the internal code
     * or deactivate the shop from Settings.
     *
     * @param  array{name: string, address: string, city: string, contact_number: string, email?: ?string}  $data
     */
    public function updateContact(Branch $branch, array $data, User $actor): Branch
    {
        return DB::transaction(function () use ($branch, $data, $actor) {
            $old = $branch->toArray();

            $branch->fill([
                'name' => $data['name'],
                'address' => $data['address'],
                'city' => $data['city'],
                'contact_number' => $data['contact_number'],
                'email' => $data['email'] ?? null,
            ])->save();

            $this->auditLogService->log(
                'branch.contact_updated',
                $branch,
                $old,
                $branch->fresh()->toArray(),
                $actor
            );

            return $branch->fresh();
        });
    }

    /**
     * Activate or deactivate a branch.
     *
     * Deactivating blocks all branch users from logging in (enforced in AuthService).
     */
    public function setStatus(Branch $branch, BranchStatus $status, User $actor): Branch
    {
        return DB::transaction(function () use ($branch, $status, $actor) {
            $old = ['status' => $branch->status?->value];

            $branch->forceFill(['status' => $status])->save();

            $action = $status === BranchStatus::ACTIVE ? 'branch.activated' : 'branch.deactivated';

            $this->auditLogService->log(
                $action,
                $branch,
                $old,
                ['status' => $status->value],
                $actor
            );

            return $branch->fresh();
        });
    }

    /**
     * Load a branch with users for the detail screen.
     *
     * Includes soft-deleted (deactivated) users so a replaced Admin still
     * appears in history. Never filters by created_by.
     */
    public function findWithUsers(Branch $branch): Branch
    {
        return $branch->load([
            'users' => fn ($q) => $q->withTrashed()->orderBy('name'),
            'activeAdmins',
            'creator',
            'updater',
        ]);
    }

    /**
     * Audit log entries for a branch, newest first.
     *
     * @return Collection<int, AuditLog>
     */
    public function auditEntries(Branch $branch, int $limit = 50)
    {
        return AuditLog::query()
            ->with('user')
            ->where('model_type', Branch::class)
            ->where('model_id', $branch->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
