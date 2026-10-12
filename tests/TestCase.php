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
     * Switch tax on or off and make `$rate` the default IVA rate (0 = no rate
     * list at all, so new documents carry no tax). Replaces the old fixed
     * tax_rate setting now that IVA lives in the tax_rates list.
     */
    protected function useTax(bool $enabled, float $rate = 0.0): void
    {
        \App\Models\TaxRate::withTrashed()->forceDelete();

        app(\App\Services\SettingService::class)->setGroup(
            \App\Enums\SettingGroup::TAX,
            ['tax_enabled' => $enabled],
            \App\Models\User::factory()->create()
        );

        if ($rate > 0) {
            \App\Models\TaxRate::query()->create([
                'kind' => 'iva',
                'name' => 'IVA '.rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.').'%',
                'rate' => $rate,
                'is_default' => true,
                'is_active' => true,
            ]);
        }
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
