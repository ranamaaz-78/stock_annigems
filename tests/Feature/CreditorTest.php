<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Creditor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creditor registry: branch-scoped CRUD, picker search, and SuperAdmin denial.
 */
class CreditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Branch admin with a live branch.
     */
    private function branchAdmin(): User
    {
        $branch = Branch::factory()->create();

        return User::factory()->admin($branch->id)->create();
    }

    public function test_index_create_show_and_edit_render(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)->get(route('branch.creditors.index'))->assertOk();
        $this->actingAs($admin)->get(route('branch.creditors.create'))->assertOk();

        $creditor = Creditor::factory()->create([
            'branch_id' => $admin->branch_id,
            'name' => 'Ahmed Jewellers',
        ]);

        $this->actingAs($admin)->get(route('branch.creditors.show', $creditor))
            ->assertOk()
            ->assertSee('Ahmed Jewellers');
        $this->actingAs($admin)->get(route('branch.creditors.edit', $creditor))->assertOk();
    }

    public function test_store_creates_a_branch_creditor(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)
            ->post(route('branch.creditors.store'), [
                'name' => 'Karachi Gems',
                'phone' => '03001234567',
                'email' => 'dealer@example.com',
                'city' => 'Karachi',
            ])
            ->assertRedirect();

        $creditor = Creditor::query()->firstOrFail();
        $this->assertSame($admin->branch_id, $creditor->branch_id);
        $this->assertSame('Karachi Gems', $creditor->name);
        $this->assertStringStartsWith('CRD-', $creditor->creditor_code);
        $this->assertTrue($creditor->status);
    }

    public function test_search_returns_a_json_list_of_active_creditors(): void
    {
        $admin = $this->branchAdmin();
        Creditor::factory()->create([
            'branch_id' => $admin->branch_id,
            'name' => 'Ahmed Jewellers',
            'phone' => '03001112233',
        ]);
        Creditor::factory()->create([
            'branch_id' => $admin->branch_id,
            'name' => 'Hidden Dealer',
            'phone' => '03001112234',
            'status' => false,
        ]);

        $this->actingAs($admin)
            ->getJson(route('branch.creditors.search', ['q' => 'Ahmed']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.creditors.0.name', 'Ahmed Jewellers');

        $creditors = $this->actingAs($admin)
            ->getJson(route('branch.creditors.search'))
            ->json('data.creditors');

        $this->assertIsList($creditors);
        $this->assertCount(1, $creditors);
        $this->assertSame('Ahmed Jewellers', $creditors[0]['name']);
    }

    public function test_phone_is_unique_per_branch_not_globally(): void
    {
        $admin = $this->branchAdmin();
        $other = User::factory()->admin(Branch::factory()->create()->id)->create();

        Creditor::factory()->create([
            'branch_id' => $other->branch_id,
            'phone' => '03005556666',
        ]);

        $this->actingAs($admin)
            ->post(route('branch.creditors.store'), [
                'name' => 'Same Phone Other Branch',
                'phone' => '03005556666',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('branch.creditors.store'), [
                'name' => 'Duplicate Same Branch',
                'phone' => '03005556666',
            ])
            ->assertSessionHasErrors('phone');
    }

    public function test_deactivate_hides_creditor_from_search(): void
    {
        $admin = $this->branchAdmin();
        $creditor = Creditor::factory()->create([
            'branch_id' => $admin->branch_id,
            'name' => 'To Hide',
        ]);

        $this->actingAs($admin)
            ->post(route('branch.creditors.deactivate', $creditor), ['confirm' => '1'])
            ->assertRedirect(route('branch.creditors.show', $creditor));

        $this->assertFalse($creditor->fresh()->status);

        $creditors = $this->actingAs($admin)
            ->getJson(route('branch.creditors.search'))
            ->json('data.creditors');

        $this->assertSame([], $creditors);
    }

    public function test_activate_puts_creditor_back_in_search(): void
    {
        $admin = $this->branchAdmin();
        $creditor = Creditor::factory()->create([
            'branch_id' => $admin->branch_id,
            'name' => 'Was Hidden',
            'status' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('branch.creditors.show', $creditor))
            ->assertOk()
            ->assertSee('Activate');

        $this->actingAs($admin)
            ->post(route('branch.creditors.activate', $creditor))
            ->assertRedirect(route('branch.creditors.show', $creditor));

        $this->assertTrue($creditor->fresh()->status);

        $creditors = $this->actingAs($admin)
            ->getJson(route('branch.creditors.search'))
            ->json('data.creditors');

        $this->assertCount(1, $creditors);
        $this->assertSame('Was Hidden', $creditors[0]['name']);
    }

    public function test_other_branch_creditor_is_forbidden(): void
    {
        $admin = $this->branchAdmin();
        $foreign = Creditor::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $this->actingAs($admin)
            ->get(route('branch.creditors.show', $foreign))
            ->assertForbidden();
    }

    public function test_superadmin_cannot_view_a_creditor(): void
    {
        $superAdmin = User::factory()->create();
        $creditor = Creditor::factory()->create();

        $this->assertFalse($superAdmin->can('view', $creditor));
        $this->assertFalse($superAdmin->can('viewAny', Creditor::class));
    }

    public function test_performas_nav_is_outside_sales(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)
            ->get(route('branch.dashboard'))
            ->assertOk()
            ->assertSee('Performas')
            ->assertSee(route('branch.creditors.index', absolute: false))
            ->assertSee(route('branch.performas.index', absolute: false));
    }
}
