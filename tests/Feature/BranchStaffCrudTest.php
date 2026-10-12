<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Branch staff CRUD: create with credentials and permission grid persistence.
 */
class BranchStaffCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Admin can create cashier with must_change_password and flash credentials.
     */
    public function test_admin_can_create_staff_with_generated_password(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $response = $this->actingAs($admin)->post(route('branch.users.store'), [
            'name' => 'Test Cashier',
            'email' => 'cashier@test.com',
            'phone' => '+92 300 1234567',
            'role' => 'cashier',
            'status' => 'active',
            'password_mode' => 'auto',
            'permissions' => ['pos.access', 'invoices.view', 'customers.view', 'items.view'],
        ]);

        $response->assertRedirect(route('branch.users.index'));
        $response->assertSessionHas('generated_credentials');

        $user = User::query()->where('email', 'cashier@test.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->must_change_password);
        $this->assertSame($branch->id, $user->branch_id);
    }
}
