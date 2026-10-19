<?php

namespace App\Models;

use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog
 *
 * Immutable-style record of who changed what. Written by AuditLogService on
 * every create/update/delete of important Module 1 entities.
 *
 * user_role is a snapshot of the actor's role at log time — never resolved
 * from users.role when the entry is displayed.
 */
class AuditLog extends Model
{
    use TracksUserAttribution;

    /**
     * Mass-assignable attributes.
     * user_role is written by TracksUserAttribution, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'branch_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * Audit rows are append-only. Map create-attribution onto user_id/user_role
     * and skip updated_* so later code cannot rewrite a log via the trait.
     *
     * @return array{created_by: ?string, created_by_role: ?string, updated_by: ?string, updated_by_role: ?string}
     */
    public function attributionColumnMap(): array
    {
        return [
            'created_by' => 'user_id',
            'created_by_role' => 'user_role',
            'updated_by' => null,
            'updated_by_role' => null,
        ];
    }

    /**
     * Actor who performed the audited action (includes deactivated users).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Related branch context, if any.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Human-readable action label for timelines.
     */
    public function actionLabel(): string
    {
        return __(match ($this->action) {
            'branch.created' => 'Branch created',
            'branch.updated' => 'Branch updated',
            'branch.activated' => 'Branch activated',
            'branch.deactivated' => 'Branch deactivated',
            'user.created' => 'User created',
            'user.activated' => 'User activated',
            'user.deactivated' => 'User deactivated',
            'user.password_reset_by_admin' => 'Password reset by admin',
            'user.password_changed' => 'Password changed',
            'user.password_reset' => 'Password reset',
            'gold_rate.created' => 'Gold rate set',
            'customer.created' => 'Customer created',
            'customer.updated' => 'Customer updated',
            'customer.deactivated' => 'Customer deactivated',
            'quotation.created' => 'Quotation created',
            'quotation.updated' => 'Quotation updated',
            'quotation.cancelled' => 'Quotation cancelled',
            'quotation.converted' => 'Quotation converted',
            'invoice.created' => 'Invoice created',
            'invoice.voided' => 'Invoice voided',
            default => ucfirst(str_replace(['.', '_'], [' — ', ' '], $this->action)),
        });
    }
}
