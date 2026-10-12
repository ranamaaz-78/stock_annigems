<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Grant the Stones → Images folder to roles that already manage stone packets.
 *
 * Seeders stay the catalogue of record. This migration only attaches the new
 * permission on databases that were seeded before the folder existed, so the
 * nav item appears after migrate without a full re-seed (re-seed would sync
 * and wipe custom role edits).
 */
return new class extends Migration
{
    /**
     * Insert stones.images and attach it beside stones.view.
     */
    public function up(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['name' => 'stones.images'],
            [
                'module' => 'stones',
                'display_name' => 'Manage Stone Images',
                'description' => 'Branch image folder under Stones: upload, preview, and rename',
            ]
        );

        Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'stones.view'))
            ->each(function (Role $role) use ($permission): void {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            });
    }

    /**
     * Drop the permission. Pivot rows cascade with the permission delete.
     */
    public function down(): void
    {
        Permission::query()->where('name', 'stones.images')->delete();
    }
};
