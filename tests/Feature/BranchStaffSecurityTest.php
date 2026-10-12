<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Branch staff security rules: role escalation blocked, self-edit blocked, session purge.
 */
class BranchStaffSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Admin cannot create another Admin via staff form.
     */
    public function test_admin_cannot_create_admin_role(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.users.store'), [
            'name' => 'Fake Admin',
            'email' => 'fake-admin@test.com',
            'role' => 'admin',
            'status' => 'active',
            'password_mode' => 'auto',
            'permissions' => [],
        ])->assertSessionHasErrors('role');
    }

    /**
     * Deactivating a user removes their database sessions.
     */
    public function test_deactivate_purges_user_sessions(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $staff = User::factory()->cashier($branch->id)->create();

        DB::table('sessions')->insert([
            'id' => 'test-session-id',
            'user_id' => $staff->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)->post(route('branch.users.deactivate', $staff));

        $this->assertDatabaseMissing('sessions', ['user_id' => $staff->id]);
        $this->assertSame(UserStatus::INACTIVE, $staff->fresh()->status);
    }
}
