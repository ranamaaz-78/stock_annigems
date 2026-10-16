<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Creditor;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NIE / NIF on customers and creditors: optional, stored upper-case, shown on the detail page.
 */
class NieFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    private function admin(): User
    {
        return User::factory()->admin(Branch::factory()->create()->id)->create();
    }

    public function test_customer_nie_is_optional_and_stored(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('branch.customers.store'), ['name' => 'Ana Ruiz', 'phone' => '03001110001'])->assertRedirect();
        $this->assertNull(Customer::query()->where('phone', '03001110001')->firstOrFail()->nie);

        $this->actingAs($admin)->post(route('branch.customers.store'), ['name' => 'Joe Smith', 'phone' => '03001110002', 'nie' => 'x1234567l'])->assertRedirect();
        $customer = Customer::query()->where('phone', '03001110002')->firstOrFail();
        $this->assertSame('X1234567L', $customer->nie);

        $this->actingAs($admin)->get(route('branch.customers.show', $customer))->assertOk()->assertSee('X1234567L');
        $this->actingAs($admin)->get(route('branch.customers.edit', $customer))->assertOk()->assertSee('NIE / NIF');

        $this->actingAs($admin)->put(route('branch.customers.update', $customer), ['name' => 'Joe Smith', 'phone' => '03001110002', 'nie' => ''])->assertRedirect();
        $this->assertNull($customer->fresh()->nie);
    }

    public function test_creditor_nie_is_optional_and_stored(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('branch.creditors.store'), ['name' => 'Luna SL', 'phone' => '03002220001'])->assertRedirect();
        $this->assertNull(Creditor::query()->where('phone', '03002220001')->firstOrFail()->nie);

        $this->actingAs($admin)->post(route('branch.creditors.store'), ['name' => 'Sol SL', 'phone' => '03002220002', 'nie' => 'b12345678'])->assertRedirect();
        $creditor = Creditor::query()->where('phone', '03002220002')->firstOrFail();
        $this->assertSame('B12345678', $creditor->nie);

        $this->actingAs($admin)->get(route('branch.creditors.show', $creditor))->assertOk()->assertSee('B12345678');
        $this->actingAs($admin)->post(route('branch.creditors.store'), ['name' => 'Bad', 'phone' => '03002220003', 'nie' => '<script>'])->assertSessionHasErrors('nie');
    }

    public function test_pos_has_the_field_and_saves_it_with_a_new_customer_or_creditor(): void
    {
        $this->seed(\Database\Seeders\MetalPuritySeeder::class);
        $admin = $this->admin();
        $purity = \App\Models\Purity::query()->where('name', '22K')->firstOrFail();
        $make = fn () => \App\Models\JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => \App\Enums\JewelleryItemStatus::AVAILABLE,
        ]);

        $this->actingAs($admin)->get(route('branch.pos.show'))->assertOk()->assertSee('data-pos-nie', false)->assertSee('data-pos-address', false);

        $line = fn ($item) => [[
            'line_type' => 'jewellery_item',
            'jewellery_item_id' => $item->id,
            'quantity' => 1,
            'gold_rate_applied' => 1000,
            'making_charge_type' => 'none',
            'making_charge_value' => 0,
        ]];

        // A new customer from the POS keeps the NIE typed there.
        $this->actingAs($admin)->postJson(route('branch.quotations.store'), [
            'walkin_name' => 'Pablo Gil',
            'walkin_phone' => '03005550001',
            'walkin_nie' => 'y7654321x',
            'walkin_address' => 'Calle Sol 5, Madrid',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none', 'discount_value' => 0, 'tax_type' => 'none', 'tax_rate' => 0, 'apply_tax' => false,
            'lines' => $line($make()),
        ])->assertOk();
        $pablo = Customer::query()->where('phone', '03005550001')->firstOrFail();
        $this->assertSame('Y7654321X', $pablo->nie);
        $this->assertSame('Calle Sol 5, Madrid', $pablo->address);

        // A new creditor from the POS performa too.
        $this->actingAs($admin)->postJson(route('branch.performas.store'), [
            'walkin_name' => 'Joyas Sol',
            'walkin_phone' => '03005550002',
            'walkin_nie' => 'b87654321',
            'walkin_address' => 'Plaza Luna 2, Sevilla',
            'performa_date' => now()->toDateString(),
            'lines' => $line($make()),
        ])->assertOk();
        $sol = Creditor::query()->where('phone', '03005550002')->firstOrFail();
        $this->assertSame('B87654321', $sol->nie);
        $this->assertSame('Plaza Luna 2, Sevilla', $sol->address);

        // The registries the phone field searches carry the NIE, so picking someone fills it.
        $this->actingAs($admin)->getJson(route('branch.customers.search', ['catalog' => 1]))
            ->assertOk()->assertJsonPath('data.customers.0.nie', 'Y7654321X')->assertJsonPath('data.customers.0.address', 'Calle Sol 5, Madrid');
        $this->actingAs($admin)->getJson(route('branch.creditors.search', ['catalog' => 1]))
            ->assertOk()->assertJsonPath('data.creditors.0.nie', 'B87654321')->assertJsonPath('data.creditors.0.address', 'Plaza Luna 2, Sevilla');

        // A malformed one is refused rather than saved.
        $this->actingAs($admin)->postJson(route('branch.quotations.store'), [
            'walkin_name' => 'Bad Nie', 'walkin_phone' => '03005550003', 'walkin_nie' => '<b>',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none', 'discount_value' => 0, 'tax_type' => 'none', 'tax_rate' => 0, 'apply_tax' => false,
            'lines' => $line($make()),
        ])->assertStatus(422);
    }
}
