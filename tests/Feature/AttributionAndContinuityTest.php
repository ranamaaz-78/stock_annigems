<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchService;
use App\Services\UserService;
use App\Support\AttributionFormatter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH 01: attribution snapshots, Admin replacement continuity, deactivation.
 */
class AttributionAndContinuityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TracksUserAttribution writes creator id + role from the authenticated user.
     */
    public function test_create_snapshots_authenticated_user_and_role(): void
    {
        $super = User::factory()->create(['name' => 'Super Admin']);
        $this->actingAs($super);

        $branch = app(BranchService::class)->create([
            'name' => 'Gulberg Boutique',
            'code' => 'LHR-01',
            'address' => '1 Main Blvd',
            'city' => 'Lahore',
            'contact_number' => '+92 300 1111111',
            'status' => 'active',
        ], $super);

        $this->assertSame($super->id, $branch->created_by);
        $this->assertSame(UserRole::SUPERADMIN->value, $branch->created_by_role);
        $this->assertNull($branch->updated_by);
    }

    /**
     * Request input cannot set attribution columns on a branch.
     */
    public function test_attribution_fields_are_ignored_from_request_input(): void
    {
        $super = User::factory()->create();
        $intruder = User::factory()->create(['name' => 'Intruder']);

        $this->actingAs($super)
            ->post(route('superadmin.branches.store'), [
                'name' => 'Request Injection Branch',
                'code' => 'INJ-01',
                'address' => 'Somewhere',
                'city' => 'Karachi',
                'contact_number' => '+92 300 2222222',
                'status' => 'active',
                'created_by' => $intruder->id,
                'created_by_role' => UserRole::CASHIER->value,
                'updated_by' => $intruder->id,
                'updated_by_role' => UserRole::CASHIER->value,
            ])
            ->assertRedirect();

        $branch = Branch::query()->where('code', 'INJ-01')->firstOrFail();

        $this->assertSame($super->id, $branch->created_by);
        $this->assertSame(UserRole::SUPERADMIN->value, $branch->created_by_role);
        $this->assertNotSame($intruder->id, $branch->created_by);
    }

    /**
     * Seeders / console writes get a clear system marker, not a silent NULL role.
     */
    public function test_unauthenticated_create_writes_system_marker(): void
    {
        $this->assertGuest();

        $branch = Branch::factory()->create();

        $this->assertNull($branch->created_by);
        $this->assertSame(AttributionFormatter::SYSTEM_ROLE, $branch->created_by_role);
    }

    /**
     * Promoting a user later must not rewrite the role stored on past records.
     */
    public function test_stored_role_is_a_snapshot_not_a_live_lookup(): void
    {
        $super = User::factory()->create();
        $branch = Branch::factory()->create();

        $admin = User::factory()->admin($branch->id)->create([
            'name' => 'Ali Khan',
            'role' => UserRole::ADMIN,
        ]);

        $this->actingAs($admin);
        $branch->update(['address' => 'Updated by Ali']);

        $branch->refresh();
        $this->assertSame($admin->id, $branch->updated_by);
        $this->assertSame(UserRole::ADMIN->value, $branch->updated_by_role);

        // Promotion after the fact — saveQuietly so we do not rewrite updated_by.
        $admin->forceFill(['role' => UserRole::MANAGER])->saveQuietly();

        $branch->refresh();
        $this->assertSame(UserRole::ADMIN->value, $branch->updated_by_role);

        $line = AttributionFormatter::updatedLine($branch->load('updater'));
        $this->assertStringContainsString('Ali Khan (Admin)', $line);
        $this->assertStringNotContainsString('Manager', $line);
    }

    /**
     * Deactivation is a status change plus soft delete — never a hard delete.
     */
    public function test_deactivation_is_status_change_and_soft_delete(): void
    {
        $super = User::factory()->create();
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create(['name' => 'Outgoing']);

        $this->actingAs($super);
        app(UserService::class)->setStatus($admin, UserStatus::INACTIVE, $super);

        $admin = User::query()->withTrashed()->findOrFail($admin->id);

        $this->assertTrue($admin->trashed());
        $this->assertSame(UserStatus::INACTIVE, $admin->status);
        $this->assertNotNull(User::query()->withTrashed()->find($admin->id));
        $this->assertNull(User::query()->find($admin->id));
    }

    /**
     * created_by foreign keys restrict hard-delete so history cannot be orphaned.
     */
    public function test_force_deleting_a_creator_is_restricted(): void
    {
        $super = User::factory()->create();
        $this->actingAs($super);
        Branch::factory()->create();

        $this->expectException(QueryException::class);
        $super->forceDelete();
    }

    /**
     * Last remaining active Admin is flagged so the UI can show the SweetAlert.
     */
    public function test_users_index_flags_the_only_active_admin(): void
    {
        $super = User::factory()->create();
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create(['name' => 'Solo Admin']);

        $this->actingAs($super)
            ->get(route('superadmin.users.index'))
            ->assertOk()
            ->assertSee('data-only-admin="1"', false);

        User::factory()->admin($branch->id)->create(['name' => 'Replacement']);

        $html = $this->actingAs($super)->get(route('superadmin.users.index'))->getContent();

        $this->assertStringContainsString('data-only-admin="0"', $html);
    }

    /**
     * Admin replacement: create B first, deactivate A, B still sees the branch,
     * and A's historical attribution still reads Admin (inactive).
     */
    public function test_admin_replacement_preserves_data_and_attribution(): void
    {
        $this->seedModuleTwo();

        $super = User::factory()->create(['name' => 'Super Admin']);
        $this->actingAs($super);

        $userService = app(UserService::class);
        $branchService = app(BranchService::class);

        $branch = $branchService->create([
            'name' => 'DHA Boutique',
            'code' => 'LHR-02',
            'address' => 'Original address',
            'city' => 'Lahore',
            'contact_number' => '+92 300 3333333',
            'status' => 'active',
        ], $super);

        $adminA = $userService->createBranchAdmin([
            'branch_id' => $branch->id,
            'name' => 'Admin A',
            'email' => 'admin-a@example.com',
            'password_mode' => 'auto',
        ], $super)['user'];

        // Admin A performs an update — this is the historical record we assert later.
        $this->actingAs($adminA);
        $branch->update(['address' => 'Address entered by Admin A']);

        $adminA->forceFill(['must_change_password' => false])->saveQuietly();

        // Create the replacement WHILE A is still active (required replacement flow).
        $this->actingAs($super);
        $adminB = $userService->createBranchAdmin([
            'branch_id' => $branch->id,
            'name' => 'Admin B',
            'email' => 'admin-b@example.com',
            'password_mode' => 'auto',
        ], $super)['user'];

        $adminB->forceFill(['must_change_password' => false])->saveQuietly();

        $this->assertSame(2, User::query()->where('branch_id', $branch->id)->where('role', UserRole::ADMIN)->count());

        $userService->setStatus($adminA, UserStatus::INACTIVE, $super);

        $adminA = User::query()->withTrashed()->findOrFail($adminA->id);
        $this->assertTrue($adminA->trashed());
        $this->assertSame(UserStatus::INACTIVE, $adminA->status);

        $branch->refresh();
        $this->assertSame('Address entered by Admin A', $branch->address);
        $this->assertSame($adminA->id, $branch->updated_by);
        $this->assertSame(UserRole::ADMIN->value, $branch->updated_by_role);

        // Admin B can still operate the branch; data is not filtered by created_by.
        $this->actingAs($adminB)
            ->get(route('branch.dashboard'))
            ->assertOk()
            ->assertSee('DHA Boutique', false);

        $this->assertTrue(
            User::query()->withTrashed()->where('branch_id', $branch->id)->whereKey($adminA->id)->exists()
        );

        $this->actingAs($super);

        $this->get(route('superadmin.branches.show', $branch))
            ->assertOk()
            ->assertSee('Admin A (Admin) (inactive)', false)
            ->assertSee('Address entered by Admin A', false);

        $this->get(route('superadmin.users.show', $adminA))
            ->assertOk()
            ->assertSee('Admin A', false)
            ->assertSee('(inactive)', false);

        $audit = AuditLog::query()->where('model_type', User::class)->where('model_id', $adminA->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame(UserRole::SUPERADMIN->value, $audit->user_role);

        $at = now();
        $line = AttributionFormatter::actorLine($adminA, UserRole::ADMIN->value, $at);
        $this->assertSame(
            'Admin A (Admin) (inactive) — '.AttributionFormatter::timestamp($at),
            $line
        );
    }

    /**
     * Missing user row still renders a fallback name plus the stored role.
     */
    public function test_formatter_uses_fallback_name_when_user_is_missing(): void
    {
        $line = AttributionFormatter::actorLine(null, UserRole::ADMIN->value, now());

        $this->assertStringContainsString(AttributionFormatter::FALLBACK_NAME, $line);
        $this->assertStringContainsString('(Admin)', $line);
        $this->assertStringNotContainsString('Unknown', $line);
    }
}
