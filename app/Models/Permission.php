<?php

namespace App\Models;

use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission
 *
 * A discrete module.action capability (e.g. items.create). Authorization
 * checks use permission names via HasPermissions / PermissionService — never
 * hard-coded role name comparisons in business logic.
 *
 * No uuid: permissions are referenced by name in code and policies.
 */
class Permission extends Model
{
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'module',
        'display_name',
        'description',
    ];

    /**
     * Roles that include this permission by default.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')
            ->withTimestamps();
    }

    /**
     * Users with explicit grant/revoke overrides for this permission.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'permission_user')
            ->withPivot('granted')
            ->withTimestamps();
    }

    /**
     * Scope permissions to a UI module grouping.
     *
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }
}
