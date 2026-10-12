<?php

namespace Tests\Feature;

use App\Enums\BranchStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Log in as": a Super Admin opens a branch account with one click. It is a
 * normal login: the Super Admin session ends, the account is signed in, and
 * logging out of it lands on the login page.
 */
class LoginAsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Factory default role is Super Admin.
     */
    private function superAdmin(): User
    {
        return User::factory()->create();
    }

    /**
     * One click signs in as the user; the Super Admin session is gone.
     */
    public function test_super_admin_is_logged_out_and_signed_in_as_the_user(): void
    {
        $super = $this->superAdmin();
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier($branch->id)->create();

        $this->actingAs($super)
            ->post(route('superadmin.users.login-as', $cashier))
            ->assertRedirect(route('branch.dashboard'));

        $this->assertSame($cashier->id, auth()->id());

        // A real login: no banner, no way back, and the login time is recorded.
        $this->get(route('branch.dashboard'))->assertOk()->assertDontSee('Return to Super Admin');
        $this->assertNotNull($cashier->fresh()->last_login_at);

        // The Super Admin pages are closed to this account.
        $this->get(route('superadmin.users.index'))->assertForbidden();
    }

    /**
     * Logging out of that account ends the session and goes to the login page.
     */
    public function test_logging_out_lands_on_the_login_page(): void
    {
        $super = $this->superAdmin();
        $cashier = User::factory()->cashier(Branch::factory()->create()->id)->create();

        $this->actingAs($super)->post(route('superadmin.users.login-as', $cashier));
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();

        // Nothing of the Super Admin is left to fall back on.
        $this->get(route('superadmin.dashboard'))->assertRedirect(route('login'));
    }

    /**
     * The use is written to the audit log under the Super Admin's name.
     */
    public function test_each_use_is_audited_under_the_super_admins_name(): void
    {
        $super = $this->superAdmin();
        $cashier = User::factory()->cashier(Branch::factory()->create()->id)->create();

        $this->actingAs($super)->post(route('superadmin.users.login-as', $cashier));

        $log = AuditLog::query()->where('action', 'user.admin_login_as')->firstOrFail();
        $this->assertSame($super->id, (int) $log->user_id);
        $this->assertSame($cashier->id, (int) $log->model_id);
    }

    /**
     * Only a Super Admin can do it, and never against a Super Admin or oneself.
     */
    public function test_only_super_admin_can_and_not_against_super_admins(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $cashier = User::factory()->cashier($branch->id)->create();
        $super = $this->superAdmin();
        $otherSuper = $this->superAdmin();

        $this->actingAs($admin)->post(route('superadmin.users.login-as', $cashier))->assertForbidden();
        $this->assertSame($admin->id, auth()->id());

        $this->actingAs($super)->post(route('superadmin.users.login-as', $otherSuper))->assertForbidden();
        $this->actingAs($super)->post(route('superadmin.users.login-as', $super))->assertForbidden();
        $this->assertSame($super->id, auth()->id());
    }

    /**
     * An inactive account, or one on an inactive branch, is refused with a
     * reason and the Super Admin stays signed in.
     */
    public function test_inactive_accounts_are_refused_with_a_reason(): void
    {
        $super = $this->superAdmin();
        $inactive = User::factory()->cashier(Branch::factory()->create()->id)->create(['status' => UserStatus::INACTIVE]);

        $this->actingAs($super)
            ->from(route('superadmin.users.index'))
            ->post(route('superadmin.users.login-as', $inactive))
            ->assertRedirect(route('superadmin.users.index'))
            ->assertSessionHas('error');
        $this->assertSame($super->id, auth()->id());

        $closed = Branch::factory()->create();
        $staff = User::factory()->cashier($closed->id)->create();
        $closed->forceFill(['status' => BranchStatus::INACTIVE])->save();

        $this->actingAs($super)
            ->from(route('superadmin.users.index'))
            ->post(route('superadmin.users.login-as', $staff->fresh()))
            ->assertSessionHas('error');
        $this->assertSame($super->id, auth()->id());
    }

    /**
     * A pending password change is the account's own rule, so it applies as
     * in any login.
     */
    public function test_a_pending_password_change_applies_as_in_a_real_login(): void
    {
        $super = $this->superAdmin();
        $cashier = User::factory()->cashier(Branch::factory()->create()->id)->create(['must_change_password' => true]);

        $this->actingAs($super)
            ->post(route('superadmin.users.login-as', $cashier))
            ->assertRedirect(route('password.change'));

        $this->get(route('branch.dashboard'))->assertRedirect(route('password.change'));
    }

    /**
     * The users list and the detail page offer the button for active branch users.
     */
    public function test_users_list_and_detail_offer_log_in_as(): void
    {
        $super = $this->superAdmin();
        $cashier = User::factory()->cashier(Branch::factory()->create()->id)->create();

        $this->actingAs($super)
            ->get(route('superadmin.users.index'))
            ->assertOk()
            ->assertSee(route('superadmin.users.login-as', $cashier), false);

        $this->actingAs($super)
            ->get(route('superadmin.users.show', $cashier))
            ->assertOk()
            ->assertSee('Log in as', false);
    }
}
