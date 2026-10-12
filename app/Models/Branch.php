<?php

namespace App\Models;

use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Branch
 *
 * Top-level tenant boundary. Inventory, sales, customers, and branch users
 * are scoped by branch_id. Soft-deleted only — never hard deleted.
 *
 * created_by is attribution only: it records who registered the branch and
 * grants no ownership. Replacing a Branch Admin does not hide or reassign
 * this row.
 *
 * Public URLs bind on uuid (HasUuid). Integer id is internal only.
 */
class Branch extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;
    use TracksUserAttribution;

    /**
     * Mass-assignable attributes. Status is set via service using BranchStatus enum.
     * Attribution columns are stripped by TracksUserAttribution — never fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'contact_number',
        'email',
        'status',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
        ];
    }

    /**
     * All users belonging to this branch, including deactivated (soft-deleted)
     * accounts. SuperAdmin detail screens must still show former Admins.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Currently active Branch Admin, if one is assigned.
     *
     * During a replacement there may briefly be two active Admins; HasOne
     * returns one of them. Prefer activeAdmins() when listing.
     */
    public function admin(): HasOne
    {
        return $this->hasOne(User::class)
            ->where('role', UserRole::ADMIN->value)
            ->where('status', UserStatus::ACTIVE->value);
    }

    /**
     * Every currently active Admin on this branch (replacement overlap).
     */
    public function activeAdmins(): HasMany
    {
        return $this->hasMany(User::class)
            ->where('role', UserRole::ADMIN->value)
            ->where('status', UserStatus::ACTIVE->value);
    }

    /**
     * Whether the branch is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === BranchStatus::ACTIVE;
    }
}
