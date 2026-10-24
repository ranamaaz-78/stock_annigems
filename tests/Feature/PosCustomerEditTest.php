<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Creditor;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Stone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer or creditor loaded into the POS can be corrected there: name,
 * email, NIE and address are saved to their record with the ticket. The phone
 * number is the identity and never changes this way.
 */
class PosCustomerEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->admin = User::factory()->admin(Branch::factory()->create()->id)->create();
    }

    private function stone(): Stone
    {
        $this->actingAs($this->admin)->post(route('branch.stones.store'), [
            'name' => 'Lot', 'status' => 'in_stock', 'pricing_mode' => 'per_piece', 'price_per_piece' => '7', 'pieces' => 10,
        ])->assertRedirect();

        return Stone::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(Customer $customer, Stone $stone, array $edits): array
    {
        return array_merge([
            'customer_uuid' => $customer->uuid,
            'update_customer' => true,
            'walkin_name' => $customer->name,
            'walkin_email' => (string) $customer->email,
            'walkin_nie' => (string) $customer->nie,
            'walkin_address' => (string) $customer->address,
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'discount_value' => 0,
            'tax_type' => 'none',
            'tax_rate' => 0,
            'apply_tax' => false,
            'lines' => [['line_type' => 'loose_stone', 'stone_id' => $stone->id, 'quantity' => 1]],
        ], $edits);
    }

    public function test_changes_to_a_loaded_customer_are_saved_and_appear_on_the_document(): void
    {
        $stone = $this->stone();
        $customer = Customer::factory()->create(['name' => 'Old Name', 'phone' => '03001112222', 'email' => null, 'address' => null]);

        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket($customer, $stone, [
            'walkin_name' => 'New Name',
            'walkin_email' => 'new@example.com',
            'walkin_nie' => 'x1234567l',
            'walkin_address' => 'Calle Mayor 12, Madrid',
            // The number is corrected with the rest, as long as nobody else holds it.
            'walkin_phone' => '03009998888',
        ]))->assertOk();

        $fresh = $customer->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertSame('X1234567L', $fresh->nie);
        $this->assertSame('Calle Mayor 12, Madrid', $fresh->address);
        $this->assertSame('03009998888', $fresh->phone);

        $quotation = Quotation::query()->latest('id')->firstOrFail();
        $this->assertSame($customer->id, $quotation->customer_id);
        $this->assertSame('New Name', $quotation->load('customer')->displayCustomerName());
    }

    public function test_a_field_can_be_cleared_but_the_name_cannot(): void
    {
        $stone = $this->stone();
        $customer = Customer::factory()->create(['name' => 'Keep Me', 'email' => 'old@example.com', 'address' => 'Somewhere']);

        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket($customer, $stone, [
            'walkin_name' => '',
            'walkin_email' => '',
            'walkin_address' => '',
        ]))->assertOk();

        $fresh = $customer->fresh();
        $this->assertSame('Keep Me', $fresh->name, 'a blank name never overwrites');
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->address);
    }

    public function test_a_ticket_without_the_edit_flag_leaves_the_customer_alone(): void
    {
        $stone = $this->stone();
        $customer = Customer::factory()->create(['name' => 'Same', 'email' => 'same@example.com']);

        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket($customer, $stone, [
            'update_customer' => false,
            'walkin_name' => 'Hacked',
            'walkin_email' => 'other@example.com',
        ]))->assertOk();

        $this->assertSame('Same', $customer->fresh()->name);
        $this->assertSame('same@example.com', $customer->fresh()->email);
    }

    public function test_a_cashier_may_correct_a_customer_but_a_user_without_the_permission_cannot(): void
    {
        $stone = $this->stone();
        $customer = Customer::factory()->create(['name' => 'Same']);
        $cashier = User::factory()->cashier($this->admin->branch_id)->create();

        $this->actingAs($cashier)->postJson(route('branch.quotations.store'), $this->ticket($customer, $stone, ['walkin_name' => 'By Cashier']))->assertOk();
        $this->assertSame('By Cashier', $customer->fresh()->name);

        // Inventory staff do not hold customers.edit: the service leaves the record alone.
        $inventory = User::factory()->inventoryStaff($this->admin->branch_id)->create();
        $this->assertFalse($inventory->hasPermission('customers.edit'));
        app(\App\Services\CustomerService::class)->syncFromTicket($customer, ['walkin_name' => 'By Inventory'], $inventory);
        $this->assertSame('By Cashier', $customer->fresh()->name);
    }

    public function test_changes_to_a_loaded_creditor_are_saved_on_a_performa(): void
    {
        $stone = $this->stone();
        $creditor = Creditor::factory()->create(['branch_id' => $this->admin->branch_id, 'name' => 'Old Creditor', 'address' => null]);

        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'update_customer' => true,
            'walkin_name' => 'Renamed Creditor',
            'walkin_nie' => 'b12345678',
            'walkin_address' => 'Gran Via 1',
            'performa_date' => now()->toDateString(),
            'lines' => [['line_type' => 'loose_stone', 'stone_id' => $stone->id, 'quantity' => 2]],
        ])->assertOk();

        $fresh = $creditor->fresh();
        $this->assertSame('Renamed Creditor', $fresh->name);
        $this->assertSame('B12345678', $fresh->nie);
        $this->assertSame('Gran Via 1', $fresh->address);
    }

    public function test_the_pos_page_tells_the_cashier_the_fields_are_saved(): void
    {
        $this->actingAs($this->admin)->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('are saved to their record', false);
    }
}
