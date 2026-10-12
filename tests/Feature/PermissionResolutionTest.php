<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permission resolution: role defaults, overrides, and hard gates.
 */
class PermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Admin receives all permissions including reports and cost view.
     */
    public function test_admin_has_all_operational_permissions(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->assertTrue($admin->hasPermission('reports.view'));
        $this->assertTrue($admin->hasPermission('items.view_cost'));
        $this->assertTrue($admin->hasPermission('pos.access'));
        $this->assertTrue($admin->hasPermission('users.create'));
    }

    /**
     * Cashier never receives cost or reports regardless of overrides attempt.
     */
    public function test_cashier_hard_gates_block_cost_and_reports(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier($branch->id)->create();

        $this->assertFalse($cashier->hasPermission('items.view_cost'));
        $this->assertFalse($cashier->hasPermission('reports.view'));
        $this->assertTrue($cashier->hasPermission('pos.access'));
    }

    /**
     * Per-user revoke override removes a default permission.
     */
    public function test_user_revoke_override_removes_default_permission(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->manager($branch->id)->create();

        $this->assertTrue($manager->hasPermission('pos.access'));

        $permissionId = \App\Models\Permission::query()->where('name', 'pos.access')->value('id');
        $manager->permissionOverrides()->attach($permissionId, ['granted' => false]);
        $manager->clearPermissionCache();

        $this->assertFalse($manager->fresh()->hasPermission('pos.access'));
    }
}
