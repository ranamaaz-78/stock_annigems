<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Branch scoping on User model and 403 on cross-branch URL access.
 */
class BranchScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Branch Admin only sees users from their branch in staff list route.
     */
    public function test_staff_list_is_scoped_to_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $adminA = User::factory()->admin($branchA->id)->create();
        User::factory()->manager($branchA->id)->create(['email' => 'staff-a@test.com']);
        User::factory()->manager($branchB->id)->create(['email' => 'staff-b@test.com']);

        $response = $this->actingAs($adminA)->get(route('branch.users.index'));

        $response->assertOk();
        $response->assertSee('staff-a@test.com');
        $response->assertDontSee('staff-b@test.com');
    }

    /**
     * Cross-branch user uuid returns 403, not 404.
     */
    public function test_cross_branch_user_access_returns_403(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $adminA = User::factory()->admin($branchA->id)->create();
        $otherStaff = User::factory()->manager($branchB->id)->create();

        $this->actingAs($adminA)
            ->get(route('branch.users.show', $otherStaff))
            ->assertForbidden();
    }

    /**
     * Session login loads User without BranchScope infinite recursion.
     */
    public function test_session_login_resolves_branch_user_without_recursion(): void
    {
        $branch = Branch::factory()->create();
        User::factory()->admin($branch->id)->create([
            'email' => 'branch-admin-login@test.com',
            'password' => 'password',
            'must_change_password' => false,
        ]);

        $this->post(route('login'), [
            'email' => 'branch-admin-login@test.com',
            'password' => 'password',
        ])->assertRedirect(route('branch.dashboard'));

        $this->get(route('branch.dashboard'))->assertOk();
    }
}
