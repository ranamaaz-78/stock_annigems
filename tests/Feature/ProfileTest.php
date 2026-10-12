<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * My Profile updates for branch users.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Cashier can update name and phone but not role.
     */
    public function test_user_can_update_profile_name_and_phone(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier($branch->id)->create([
            'name' => 'Old Name',
            'phone' => null,
        ]);

        $this->actingAs($cashier)->put(route('branch.profile.update'), [
            'name' => 'New Name',
            'phone' => '+92 300 9999999',
        ])->assertRedirect(route('branch.profile.edit'));

        $cashier->refresh();
        $this->assertSame('New Name', $cashier->name);
        $this->assertSame('+92 300 9999999', $cashier->phone);
    }

    /**
     * Profile page is accessible to any branch role.
     */
    public function test_manager_can_view_profile_page(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->manager($branch->id)->create();

        $this->actingAs($manager)
            ->get(route('branch.profile.edit'))
            ->assertOk()
            ->assertSee('My Profile');
    }
}
