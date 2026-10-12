<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DatabaseSeeder
 *
 * Module 1: SuperAdmin. Module 2: roles, permissions, and role defaults.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
            BackfillUserRoleIdsSeeder::class,
            MetalPuritySeeder::class,
            SettingSeeder::class,
            TaxRateSeeder::class,
        ]);
    }
}
