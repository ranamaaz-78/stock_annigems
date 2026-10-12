<?php

namespace App\Models;

use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role
 *
 * System-defined role (SuperAdmin, Admin, Manager, Cashier, Inventory Staff).
 * Default permissions are linked via role_permission pivot. Branch Admins
 * cannot create or delete roles — they assign existing roles to staff.
 *
 * No uuid: roles are never addressed by URL.
 */
class Role extends Model
{
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Default permissions granted to this role via role_permission pivot.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')
            ->withTimestamps();
    }

    /**
     * Whether this is a built-in system role that cannot be deleted.
     */
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }
}
