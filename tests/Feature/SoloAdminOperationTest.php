<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Solo Admin operation: one Admin with no staff has full branch permissions.
 */
class SoloAdminOperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Lone Admin can access POS, items, reports, and user management permissions.
     */
    public function test_solo_admin_has_all_branch_permissions(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $required = [
            'items.create',
            'barcode.generate',
            'pos.access',
            'invoices.view',
            'customers.create',
            'reports.view',
            'users.create',
        ];

        foreach ($required as $permission) {
            $this->assertTrue(
                $admin->hasPermission($permission),
                "Expected solo Admin to have {$permission}"
            );
        }
    }

    /**
     * Lone Admin can load branch dashboard without staff accounts existing.
     */
    public function test_solo_admin_can_view_dashboard(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)
            ->get(route('branch.dashboard'))
            ->assertOk()
            ->assertSee($branch->name);
    }
}
