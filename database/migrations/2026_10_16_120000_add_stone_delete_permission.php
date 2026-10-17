<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Grant stones.delete to the roles that already deactivate stones.
 *
 * Seeders stay the catalogue of record; this only attaches the new permission
 * on databases seeded before it existed, without a re-seed that would wipe
 * custom role edits.
 */
return new class extends Migration
{
    /**
     * Insert stones.delete and attach it beside stones.deactivate.
     */
    public function up(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['name' => 'stones.delete'],
            [
                'module' => 'stones',
                'display_name' => 'Delete Stones',
                'description' => 'Soft-delete a stone lot that is not held by an open document',
            ]
        );

        Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'stones.deactivate'))
            ->each(function (Role $role) use ($permission): void {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            });
    }

    /**
     * Drop the permission. Pivot rows cascade with the permission delete.
     */
    public function down(): void
    {
        Permission::query()->where('name', 'stones.delete')->delete();
    }
};
