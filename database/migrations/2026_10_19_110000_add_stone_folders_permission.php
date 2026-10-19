<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Grant stones.folders (rename or delete a material folder) to the roles that
 * already delete stones — the branch admin.
 *
 * Seeders stay the catalogue of record; this only attaches the new permission
 * on databases seeded before it existed, without a re-seed that would wipe
 * custom role edits.
 */
return new class extends Migration
{
    /**
     * Insert stones.folders and attach it beside stones.delete.
     */
    public function up(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['name' => 'stones.folders'],
            [
                'module' => 'stones',
                'display_name' => 'Manage Stone Folders',
                'description' => 'Rename a material folder, or delete it together with every lot inside',
            ]
        );

        Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'stones.delete'))
            ->each(function (Role $role) use ($permission): void {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            });
    }

    /**
     * Drop the permission. Pivot rows cascade with the permission delete.
     */
    public function down(): void
    {
        Permission::query()->where('name', 'stones.folders')->delete();
    }
};
