<?php

namespace App\Traits;

use App\Services\PermissionService;
use Illuminate\Support\Collection;

/**
 * HasPermissions
 *
 * Adds permission resolution to User. Delegates to PermissionService for
 * effective permissions (role defaults + user overrides + hard gates).
 *
 * Use $user->hasPermission('items.create') everywhere — never compare
 * $user->role === 'cashier' in business logic.
 */
trait HasPermissions
{
    /**
     * Cached effective permission names for this request lifecycle.
     *
     * @var list<string>|null
     */
    protected ?array $effectivePermissionCache = null;

    /**
     * Whether the user holds an effective permission after role defaults,
     * per-user overrides, and hard gates are applied.
     */
    public function hasPermission(string $name): bool
    {
        return in_array($name, $this->effectivePermissionNames(), true);
    }

    /**
     * All effective permission name strings for this user.
     *
     * @return list<string>
     */
    public function effectivePermissionNames(): array
    {
        if ($this->effectivePermissionCache !== null) {
            return $this->effectivePermissionCache;
        }

        $this->effectivePermissionCache = app(PermissionService::class)
            ->effectivePermissionNames($this);

        return $this->effectivePermissionCache;
    }

    /**
     * Effective permissions as Permission models keyed by name.
     *
     * @return Collection<int, \App\Models\Permission>
     */
    public function effectivePermissions(): Collection
    {
        return app(PermissionService::class)->effectivePermissions($this);
    }

    /**
     * Clear the per-request permission cache (after role/override changes).
     */
    public function clearPermissionCache(): void
    {
        $this->effectivePermissionCache = null;
    }
}
