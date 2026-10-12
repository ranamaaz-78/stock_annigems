<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH 02: hybrid UUID — integer PK internally, uuid in every public URL.
 */
class HybridUuidTest extends TestCase
{
    use RefreshDatabase;

    /**
     * HasUuid generates an ordered UUID on create and uses it as the route key.
     */
    public function test_uuid_is_generated_and_used_as_route_key(): void
    {
        $branch = Branch::factory()->create();

        $this->assertNotEmpty($branch->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $branch->uuid
        );
        $this->assertSame('uuid', $branch->getRouteKeyName());
        $this->assertSame($branch->uuid, $branch->getRouteKey());
    }

    /**
     * Integer id is hidden from serialization; uuid is present instead.
     */
    public function test_integer_id_is_hidden_from_json_and_arrays(): void
    {
        $user = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->assertArrayNotHasKey('id', $user->toArray());
        $this->assertArrayNotHasKey('id', $branch->toArray());
        $this->assertArrayNotHasKey('id', json_decode($user->toJson(), true));
        $this->assertArrayHasKey('uuid', $user->toArray());
        $this->assertArrayHasKey('uuid', $branch->toArray());

        // The attribute still exists in PHP for FKs and auth.
        $this->assertIsInt($user->id);
        $this->assertIsInt($branch->id);
    }

    /**
     * A client-supplied uuid is ignored; the server always generates one.
     */
    public function test_uuid_cannot_be_set_from_request_input(): void
    {
        $super = User::factory()->create();
        $forged = '11111111-1111-1111-1111-111111111111';

        $this->actingAs($super)
            ->post(route('superadmin.branches.store'), [
                'name' => 'UUID Injection Branch',
                'code' => 'UID-01',
                'address' => 'Somewhere',
                'city' => 'Karachi',
                'contact_number' => '+92 300 2222222',
                'status' => 'active',
                'uuid' => $forged,
                'id' => 9999,
            ])
            ->assertRedirect();

        $branch = Branch::query()->where('code', 'UID-01')->firstOrFail();

        $this->assertNotSame($forged, $branch->uuid);
        $this->assertNotSame(9999, $branch->id);
    }

    /**
     * Updating a record cannot rewrite its uuid.
     */
    public function test_uuid_is_immutable_after_create(): void
    {
        $branch = Branch::factory()->create();
        $original = $branch->uuid;

        $branch->uuid = '22222222-2222-2222-2222-222222222222';
        $branch->save();

        $this->assertSame($original, $branch->fresh()->uuid);
    }

    /**
     * SuperAdmin URLs bind on uuid; the integer id does not resolve.
     */
    public function test_superadmin_urls_use_uuid_not_integer_id(): void
    {
        $super = User::factory()->create();
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($super);

        $showUrl = route('superadmin.branches.show', $branch);
        $this->get($showUrl)->assertOk();
        $this->assertStringContainsString($branch->uuid, $showUrl);
        $this->assertStringNotContainsString('/branches/'.$branch->id, $showUrl);

        $this->get('/superadmin/branches/'.$branch->id)->assertNotFound();
        $this->get('/superadmin/users/'.$admin->id)->assertNotFound();

        $this->get(route('superadmin.users.show', $admin))->assertOk();
        $this->assertStringContainsString($admin->uuid, route('superadmin.users.show', $admin));
        $this->assertStringContainsString($admin->uuid, route('superadmin.users.deactivate', $admin));
        $this->assertStringContainsString($branch->uuid, route('superadmin.branches.edit', $branch));
    }

    /**
     * Form filters and create-admin links emit the branch uuid, not the integer id.
     */
    public function test_html_forms_and_links_do_not_expose_integer_ids(): void
    {
        $super = User::factory()->create();
        $branch = Branch::factory()->create(['name' => 'Gulberg']);
        User::factory()->admin($branch->id)->create();

        $this->actingAs($super);

        $index = $this->get(route('superadmin.users.index'))->assertOk();
        $index->assertSee('value="'.$branch->uuid.'"', false);
        $index->assertDontSee('value="'.$branch->id.'"', false);

        $show = $this->get(route('superadmin.branches.show', $branch))->assertOk();
        $show->assertSee('branch='.$branch->uuid, false);
        $show->assertDontSee('branch_id='.$branch->id, false);
        $show->assertDontSee('/superadmin/branches/'.$branch->id, false);
    }

    /**
     * Uuid is addressing only. A branch Admin still cannot open SuperAdmin URLs,
     * even with a valid uuid from their own or another branch.
     */
    public function test_valid_uuid_does_not_bypass_authorization(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $adminA = User::factory()->admin($branchA->id)->create([
            'role' => UserRole::ADMIN,
            'must_change_password' => false,
        ]);

        $this->actingAs($adminA)
            ->get(route('superadmin.branches.show', $branchB))
            ->assertForbidden();

        $this->actingAs($adminA)
            ->get(route('superadmin.branches.show', $branchA))
            ->assertForbidden();
    }

    /**
     * Relationships still use integer ids after the retrofit.
     */
    public function test_foreign_keys_still_use_integer_id(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->assertSame($branch->id, $admin->branch_id);
        $this->assertTrue($admin->branch->is($branch));
        $this->assertTrue($branch->users()->whereKey($admin->id)->exists());
    }
}
