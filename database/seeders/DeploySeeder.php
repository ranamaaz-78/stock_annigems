<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DeploySeeder
 *
 * Runs on every deploy. Only calls seeders that are safe to repeat
 * (roles, permissions, settings, role_id backfill).
 */
class DeploySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            SettingSeeder::class,
            BackfillUserRoleIdsSeeder::class,
        ]);
    }
}
