<?php

namespace Tests\Feature;

use App\Enums\StoneStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dual language: Spanish by default, English on request, remembered per
 * browser (cookie) and per account (users.locale).
 *
 * The rest of the suite runs in English (phpunit.xml); these tests switch
 * the default back to Spanish, as production has it.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        config(['app.locale' => 'es']);
    }

    private function branchAdmin(): User
    {
        return User::factory()->admin(Branch::factory()->create()->id)->create();
    }

    public function test_the_login_screen_is_spanish_by_default_with_the_language_button(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="es"', false)
            ->assertSee('Iniciar sesión')
            ->assertSee('Bienvenido de nuevo')
            ->assertSee(route('locale.switch', 'en'), false);
    }

    public function test_a_guest_switch_is_remembered_in_a_cookie(): void
    {
        $this->from(route('login'))->post(route('locale.switch', 'en'))
            ->assertRedirect(route('login'))
            ->assertCookie('locale', 'en');

        $this->withCookie('locale', 'en')->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('Welcome back')
            ->assertDontSee('Bienvenido de nuevo');
    }

    public function test_a_signed_in_switch_is_saved_on_the_account(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)->from(route('branch.dashboard'))->post(route('locale.switch', 'en'))
            ->assertRedirect(route('branch.dashboard'));
        $this->assertSame('en', $admin->fresh()->locale);

        // The account choice wins over the default, with no cookie at all.
        $this->actingAs($admin->fresh())->get(route('branch.stones.index'))
            ->assertOk()->assertSee('Logout')->assertDontSee('Cerrar sesión');

        $this->actingAs($admin)->post(route('locale.switch', 'es'));
        $this->assertSame('es', $admin->fresh()->locale);
        $this->actingAs($admin->fresh())->get(route('branch.stones.index'))
            ->assertOk()->assertSee('Cerrar sesión')->assertSee('Carpetas');
    }

    public function test_only_supported_languages_are_accepted(): void
    {
        $this->post('/locale/fr')->assertNotFound();

        // A hand-edited cookie falls back to the default.
        $this->withCookie('locale', 'fr')->get(route('login'))
            ->assertOk()->assertSee('<html lang="es"', false);
    }

    public function test_spanish_pages_enums_and_messages(): void
    {
        $admin = $this->branchAdmin();
        $admin->forceFill(['locale' => 'es'])->save();

        $this->actingAs($admin)->get(route('branch.stones.index'))
            ->assertOk()
            ->assertSee('Piedras')
            ->assertSee('Registrar piedra');

        app()->setLocale('es');
        $this->assertSame('En existencia', StoneStatus::IN_STOCK->label());

        app()->setLocale('en');
        $this->assertSame('In stock', StoneStatus::IN_STOCK->label());
    }

    public function test_validation_messages_follow_the_language(): void
    {
        $this->from(route('login'))->post(route('login'), ['email' => '', 'password' => ''])
            ->assertSessionHasErrors(['email']);

        $this->assertStringContainsString('correo', strtolower((string) session('errors')->first('email')));
    }

    public function test_dates_use_spanish_month_names(): void
    {
        app()->setLocale('es');
        Carbon::setLocale('es');

        $this->assertStringContainsString('dic', setting_date('2026-12-31 12:00:00', 'd M Y'));

        app()->setLocale('en');
        Carbon::setLocale('en');

        $this->assertStringContainsString('Dec', setting_date('2026-12-31 12:00:00', 'd M Y'));
    }

    public function test_pages_carry_the_browser_strings_for_the_language(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('window.AppI18n', false)
            ->assertSee('"locale":"es"', false);

        $this->assertSame('Cancelar', trans_js()['Cancel'] ?? null);
    }

    public function test_every_translatable_text_has_a_spanish_translation(): void
    {
        $this->artisan('lang:missing', ['locale' => 'es'])->assertExitCode(0);
    }
}
