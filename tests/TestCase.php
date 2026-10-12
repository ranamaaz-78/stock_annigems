<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Shared test setup: skip Vite so Blade views render without a manifest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Seed Module 2 roles, permissions, and default mappings.
     */
    protected function seedModuleTwo(): void
    {
        $this->seed([
            \Database\Seeders\RoleSeeder::class,
            \Database\Seeders\PermissionSeeder::class,
            \Database\Seeders\RolePermissionSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);
    }
}
