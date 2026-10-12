<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Traits\BelongsToBranch;
use App\Traits\HasPermissions;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User
 *
 * Authenticatable account. SuperAdmin has branch_id = NULL; every other role
 * must belong to exactly one branch. Deactivation is a status change plus
 * soft delete — never a hard delete — so invoices, items, and audit logs
 * keep a permanent pointer to this row.
 *
 * created_by is attribution only. Staff accounts a former Admin created
 * remain fully accessible to the replacement Admin; queries scope by
 * branch_id, never by created_by.
 *
 * Public URLs bind on uuid (HasUuid). Integer id is internal only (auth,
 * foreign keys, attribution).
 *
 * Role authority: role_id FK → roles table is authoritative. The `role` enum
 * column is a convenience mirror kept in sync by UserService / BranchStaffService.
 * Always trust role_id + roleRecord() for permission resolution.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToBranch;
    use HasFactory;
    use HasPermissions;
    use HasUuid;
    use Notifiable;
    use SoftDeletes;
    use TracksUserAttribution;

    /**
     * The attributes that are mass assignable.
     * Role and branch_id for new accounts are set in services, not from arbitrary input.
     * Attribution columns are stripped by TracksUserAttribution — never fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'role_id',
        'status',
        'last_login_at',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     * Integer `id` is also hidden by HasUuid so it never leaks in JSON.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Authoritative role record (role_id FK). Used for default permissions.
     */
    public function roleRecord(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Per-user permission overrides (grant/revoke pivot).
     */
    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user')
            ->withPivot('granted')
            ->withTimestamps();
    }

    /**
     * Accounts this user created (attribution only — not an access boundary).
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * Whether this user is a system SuperAdmin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SUPERADMIN;
    }

    /**
     * Whether this user is a branch Admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    /**
     * Whether the account is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE && ! $this->trashed();
    }
}
