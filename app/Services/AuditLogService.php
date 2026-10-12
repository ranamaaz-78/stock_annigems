<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * AuditLogService
 *
 * Single writer for audit trail entries. Every create/update/delete on
 * important Module 1 records (branches, users) must go through this service.
 *
 * user_role is NOT set here — TracksUserAttribution snapshots it on create
 * from the authenticated user (or from user_id when running unauthenticated).
 */
class AuditLogService
{
    /**
     * Persist an audit log row for a model change.
     *
     * @param  string  $action  Machine key, e.g. branch.created
     * @param  Model  $model  Affected Eloquent model
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  User|null  $actor  Defaults to the authenticated user
     */
    public function log(
        string $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null
    ): AuditLog {
        $actor = $actor ?? auth()->user();

        // Prefer explicit branch on the model; fall back to the actor's branch.
        $branchId = null;
        if (isset($model->branch_id)) {
            $branchId = $model->branch_id;
        } elseif ($model instanceof Branch) {
            $branchId = $model->id;
        } elseif ($actor?->branch_id) {
            $branchId = $actor->branch_id;
        }

        $log = new AuditLog([
            'branch_id' => $branchId,
            'action' => $action,
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);

        // Assigned directly so it is not mass-assignable from requests.
        // TracksUserAttribution snapshots user_role from this actor (or auth).
        $log->user_id = $actor?->id;
        $log->save();

        return $log;
    }
}
