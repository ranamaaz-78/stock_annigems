<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\QuotationStatus;
use App\Enums\SettingGroup;
use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\Purity;
use App\Models\Quotation;
use App\Models\Stone;
use App\Models\User;
use App\Services\InvoiceConversionService;
use App\Services\SettingService;
use App\Services\StoneStockService;
use Database\Seeders\MetalPuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fullscreen POS: permission, phone lookup, generate + print_url, tax toggle.
 */
class PosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->seed(MetalPuritySeeder::class);
    }

    /**
     * @return array{0: Branch, 1: User, 2: Purity}
     */
    private function branchAdmin(): array
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $purity = Purity::query()->where('name', '22K')->first();
        $this->assertNotNull($purity);

        return [$branch, $admin, $purity];
    }

    /**
     * Available jewellery piece on the operator's branch.
     */
    private function piece(User $admin, Purity $purity): JewelleryItem
    {
        return JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);
    }

    /**
     * Shared workspace payload for POS generate calls.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function posPayload(JewelleryItem $item, array $overrides = []): array
    {
        return array_merge([
            'walkin_name' => 'Walk-in Buyer',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'discount_value' => 0,
            'tax_type' => 'none',
            'tax_rate' => 0,
            'apply_tax' => false,
            'lines' => [[
                'line_type' => LineType::JEWELLERY_ITEM->value,
                'jewellery_item_id' => $item->id,
                'quantity' => 1,
                'gold_rate_applied' => 1000,
                'making_charge_type' => 'none',
                'making_charge_value' => 0,
            ]],
        ], $overrides);
    }

    public function test_quotation_and_invoice_lists_open_pos_for_new_documents(): void
    {
        [, $admin] = $this->branchAdmin();

        $this->actingAs($admin)
            ->get(route('branch.quotations.index'))
            ->assertOk()
            ->assertSee('New Quotation')
            ->assertSee(route('branch.pos.show'), false);

        $this->actingAs($admin)
            ->get(route('branch.invoices.index'))
            ->assertOk()
            ->assertSee('New Invoice')
            ->assertSee(route('branch.pos.show'), false);

        $this->actingAs($admin)
            ->get(route('branch.quotations.create'))
            ->assertRedirect(route('branch.pos.show', ['kind' => 'quotation']));
    }

    public function test_cashier_with_quotations_create_can_open_pos(): void
    {
        [$branch] = $this->branchAdmin();
        $cashier = User::factory()->cashier($branch->id)->create();

        $this->actingAs($cashier)
            ->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('Generate Quotation')
            ->assertSee('Generate Invoice')
            ->assertSee('Description')
            ->assertSee('Stock left')
            ->assertSee('Qty')
            ->assertSee('Amount')
            // The branch sidebar stays for navigation; there is no topbar.
            ->assertSee('sa-sidebar', false)
            ->assertDontSee('sa-topbar', false);
    }

    /**
     * Single-column document: editable issue date, customer typeahead, and
     * no add-item rail (the scan row lives on the document itself).
     */
    public function test_pos_is_a_single_column_document_with_editable_issue_date(): void
    {
        [$branch] = $this->branchAdmin();
        $cashier = User::factory()->cashier($branch->id)->create();

        $this->actingAs($cashier)
            ->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('Issue date')
            ->assertSee('data-pos-date', false)
            ->assertSee('Valid until')
            ->assertSee('Billed to')
            ->assertSee('data-pos-phone', false)
            ->assertDontSee('data-customer-search', false)
            ->assertDontSee('Walk-in')
            ->assertSee('Add items')
            ->assertSee('Notes')
            ->assertDontSee('Exit POS')
            // No kiosk bar and no right-hand add-item rail.
            ->assertDontSee('pos-bar', false)
            ->assertDontSee('pos-rail', false)
            ->assertDontSee('pos-workspace', false);
    }

    /**
     * The picker is gated like the phone lookup, so a cashier without access
     * to the customer registry can still attach an existing customer.
     */
    /**
     * POS loads the registry once. catalog=1 is the same gate as a typed search.
     */
    public function test_cashier_can_load_the_customer_catalog_once(): void
    {
        [$branch] = $this->branchAdmin();
        $cashier = User::factory()->cashier($branch->id)->create();
        $customer = Customer::factory()->create([
            'name' => 'Amina Khan',
            'phone' => '03001112233',
        ]);

        $customers = $this->actingAs($cashier)
            ->getJson(route('branch.customers.search', ['catalog' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.customers');

        $this->assertIsList($customers);
        $this->assertTrue(collect($customers)->contains(
            fn ($row) => ($row['uuid'] ?? null) === $customer->uuid && ($row['phone'] ?? null) === '03001112233'
        ));
    }

    public function test_cashier_can_search_customers_for_the_pos_picker(): void
    {
        [$branch] = $this->branchAdmin();
        $cashier = User::factory()->cashier($branch->id)->create();
        $customer = Customer::factory()->create([
            'name' => 'Amina Khan',
            'phone' => '03001112233',
        ]);

        $this->actingAs($cashier)
            ->getJson(route('branch.customers.search', ['q' => 'Amina']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customers.0.uuid', $customer->uuid)
            ->assertJsonPath('data.customers.0.name', 'Amina Khan')
            ->assertJsonPath('data.customers.0.phone', '03001112233');
    }

    public function test_inventory_staff_cannot_search_customers(): void
    {
        [$branch] = $this->branchAdmin();
        $staff = User::factory()->inventoryStaff($branch->id)->create();

        $this->actingAs($staff)
            ->getJson(route('branch.customers.search', ['q' => 'Amina']))
            ->assertForbidden();
    }

    /**
     * The cashier may back-date a document; the issue date is posted as-is.
     */
    public function test_pos_accepts_an_edited_issue_date(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);
        $backdated = now()->subDays(3)->toDateString();

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $this->posPayload($item, [
                'quotation_date' => $backdated,
            ]))
            ->assertOk();

        $quotation = Quotation::query()->latest('id')->first();
        $this->assertSame($backdated, $quotation->quotation_date->toDateString());
    }

    public function test_inventory_staff_cannot_open_pos(): void
    {
        [$branch] = $this->branchAdmin();
        $staff = User::factory()->inventoryStaff($branch->id)->create();

        $this->actingAs($staff)
            ->get(route('branch.pos.show'))
            ->assertForbidden();
    }

    public function test_phone_lookup_hit_and_miss(): void
    {
        [, $admin] = $this->branchAdmin();
        $existing = Customer::factory()->create([
            'name' => 'Amina Khan',
            'phone' => '03001112233',
            'email' => 'amina@example.com',
        ]);

        $this->actingAs($admin)
            ->getJson(route('branch.customers.lookup', ['phone' => '03001112233']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer.uuid', $existing->uuid)
            ->assertJsonPath('data.customer.name', 'Amina Khan')
            ->assertJsonPath('data.customer.email', 'amina@example.com');

        $this->actingAs($admin)
            ->getJson(route('branch.customers.lookup', ['phone' => '03999999999']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer', null);
    }

    public function test_generate_quotation_creates_customer_with_email_and_returns_print_url(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);

        $response = $this->actingAs($admin)->postJson(route('branch.quotations.store'), $this->posPayload($item, [
            'walkin_email' => 'buyer@example.com',
        ]));

        $response->assertOk()->assertJsonPath('success', true);

        $uuid = $response->json('data.uuid');
        $this->assertIsString($uuid);

        $quotation = Quotation::query()->where('uuid', $uuid)->first();
        $this->assertNotNull($quotation);
        $this->assertSame(QuotationStatus::DRAFT, $quotation->status);
        $this->assertSame(route('branch.quotations.print', $quotation), $response->json('data.print_url'));

        $customer = Customer::query()->where('phone', '03001234567')->first();
        $this->assertNotNull($customer);
        $this->assertSame('Walk-in Buyer', $customer->name);
        $this->assertSame('buyer@example.com', $customer->email);
        $this->assertSame($customer->id, $quotation->customer_id);
        $this->assertSame(JewelleryItemStatus::RESERVED, $item->fresh()->status);
    }

    public function test_generate_quotation_reuses_existing_phone_without_changing_name(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);
        $existing = Customer::factory()->create([
            'name' => 'Registered Buyer',
            'phone' => '03009876543',
            'email' => 'old@example.com',
        ]);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $this->posPayload($item, [
                'walkin_name' => 'Someone Else',
                'walkin_phone' => '03009876543',
                'walkin_email' => 'new@example.com',
            ]))
            ->assertOk();

        $this->assertSame(1, Customer::query()->where('phone', '03009876543')->count());
        $this->assertSame('Registered Buyer', $existing->fresh()->name);
        $this->assertSame('old@example.com', $existing->fresh()->email);
    }

    public function test_direct_invoice_from_pos_requires_payment_and_returns_print_url(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.invoice'), $this->posPayload($item))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);

        $this->assertSame(JewelleryItemStatus::AVAILABLE, $item->fresh()->status);

        $response = $this->actingAs($admin)->postJson(route('branch.quotations.invoice'), $this->posPayload($item, [
            'payment_method' => 'cash',
        ]));

        $response->assertOk()->assertJsonPath('success', true);

        $invoice = Invoice::query()->where('uuid', $response->json('data.uuid'))->first();
        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::COMPLETED, $invoice->status);
        $this->assertSame(route('branch.invoices.print', $invoice), $response->json('data.print_url'));
        $this->assertSame(JewelleryItemStatus::SOLD, $item->fresh()->status);
        $this->assertSame(0, Quotation::query()->count());
    }

    public function test_pos_apply_tax_false_skips_gst_when_tax_is_enabled(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);
        $super = User::factory()->create();

        $this->useTax(true, 18);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $this->posPayload($item, [
                'apply_tax' => false,
            ]))
            ->assertOk();

        $skipped = Quotation::query()->latest('id')->first();
        $this->assertSame('none', $skipped->tax_type->value);
        $this->assertSame('0.00', (string) $skipped->tax_amount);

        $second = $this->piece($admin, $purity);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $this->posPayload($second, [
                'walkin_phone' => '03007654321',
                'apply_tax' => true,
            ]))
            ->assertOk();

        $withTax = Quotation::query()->latest('id')->first();
        $this->assertSame('percentage', $withTax->tax_type->value);
        $this->assertSame('18.00', (string) $withTax->tax_rate);
        $this->assertGreaterThan(0, (float) $withTax->tax_amount);
    }

    public function test_omitted_apply_tax_still_applies_catalog_gst(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);
        $super = User::factory()->create();

        $this->useTax(true, 18);

        $payload = $this->posPayload($item);
        unset($payload['apply_tax']);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $payload)
            ->assertOk();

        $quotation = Quotation::query()->latest('id')->first();
        $this->assertSame('percentage', $quotation->tax_type->value);
        $this->assertGreaterThan(0, (float) $quotation->tax_amount);
    }

    public function test_pos_discount_ceiling_is_enforced(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);
        $super = User::factory()->create();

        app(SettingService::class)->setGroup(SettingGroup::DISCOUNT, [
            'discount_enabled' => true,
            'max_discount_percentage' => 10,
        ], $super);

        $denied = $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $this->posPayload($item, [
                'discount_type' => 'percentage',
                'discount_value' => 20,
            ]));

        $denied->assertStatus(422);
        $this->assertStringContainsString('10%', (string) $denied->json('errors.discount_value.0'));

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $this->posPayload($item, [
                'walkin_phone' => '03005554433',
                'discount_type' => 'percentage',
                'discount_value' => 10,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * POS Rate is per piece: qty 3 × 20.00 = 60.00, not 20.00.
     */
    public function test_pos_loose_stone_quantity_times_rate_is_the_line_total(): void
    {
        [, $admin] = $this->branchAdmin();
        $stone = Stone::factory()->create([
            'branch_id' => $admin->branch_id,
            'is_active' => true,
        ]);
        app(StoneStockService::class)->apply($stone, StoneMovementType::INTAKE, 5, $admin);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), [
                'walkin_name' => 'Walk-in Buyer',
                'walkin_phone' => '03007654321',
                'quotation_date' => now()->toDateString(),
                'discount_type' => 'none',
                'discount_value' => 0,
                'tax_type' => 'none',
                'tax_rate' => 0,
                'apply_tax' => false,
                'lines' => [[
                    'line_type' => LineType::LOOSE_STONE->value,
                    'stone_id' => $stone->id,
                    'quantity' => 3,
                    'price_per_piece' => 20,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $quotation = Quotation::query()->latest('id')->first();
        $line = $quotation->items()->first();

        $this->assertSame(3, (int) $line->quantity);
        $this->assertEqualsWithDelta(60.00, (float) $line->stone_value, 0.001);
        $this->assertEqualsWithDelta(60.00, (float) $line->line_total, 0.001);
        $this->assertEqualsWithDelta(60.00, (float) $quotation->subtotal, 0.001);
        $this->assertSame(2, (int) $stone->fresh()->quantity);
    }

    /**
     * Quotation holds stock immediately. Convert to invoice must not cut again.
     */
    public function test_quotation_cuts_stone_qty_and_convert_does_not_cut_again(): void
    {
        [, $admin] = $this->branchAdmin();
        $stone = Stone::factory()->create([
            'branch_id' => $admin->branch_id,
            'is_active' => true,
        ]);
        app(StoneStockService::class)->apply($stone, StoneMovementType::INTAKE, 5, $admin);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), [
                'walkin_name' => 'Walk-in Buyer',
                'walkin_phone' => '03007654322',
                'quotation_date' => now()->toDateString(),
                'discount_type' => 'none',
                'discount_value' => 0,
                'tax_type' => 'none',
                'tax_rate' => 0,
                'apply_tax' => false,
                'lines' => [[
                    'line_type' => LineType::LOOSE_STONE->value,
                    'stone_id' => $stone->id,
                    'quantity' => 3,
                    'price_per_piece' => 20,
                ]],
            ])
            ->assertOk();

        $this->assertSame(2, (int) $stone->fresh()->quantity);

        $quotation = Quotation::query()->latest('id')->first();
        $this->assertNotNull($quotation);

        app(InvoiceConversionService::class)->convert($quotation, [
            'payment_method' => 'cash',
        ], $admin);

        $this->assertSame(2, (int) $stone->fresh()->quantity);
        $this->assertSame(QuotationStatus::CONVERTED, $quotation->fresh()->status);
    }

    /**
     * Cancelling a draft quotation puts reserved stone qty back on the shelf.
     */
    public function test_cancelled_quotation_restores_stone_quantity(): void
    {
        [, $admin] = $this->branchAdmin();
        $stone = Stone::factory()->create([
            'branch_id' => $admin->branch_id,
            'is_active' => true,
        ]);
        app(StoneStockService::class)->apply($stone, StoneMovementType::INTAKE, 5, $admin);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), [
                'walkin_name' => 'Walk-in Buyer',
                'walkin_phone' => '03007654323',
                'quotation_date' => now()->toDateString(),
                'discount_type' => 'none',
                'discount_value' => 0,
                'tax_type' => 'none',
                'tax_rate' => 0,
                'apply_tax' => false,
                'lines' => [[
                    'line_type' => LineType::LOOSE_STONE->value,
                    'stone_id' => $stone->id,
                    'quantity' => 3,
                    'price_per_piece' => 20,
                ]],
            ])
            ->assertOk();

        $quotation = Quotation::query()->latest('id')->first();
        $this->assertSame(2, (int) $stone->fresh()->quantity);

        $this->actingAs($admin)
            ->post(route('branch.quotations.cancel', $quotation))
            ->assertRedirect();

        $this->assertSame(5, (int) $stone->fresh()->quantity);
        $this->assertSame(QuotationStatus::CANCELLED, $quotation->fresh()->status);
    }

    /**
     * A quoted jewellery piece leaves the POS catalog until the quote is cancelled.
     */
    public function test_quoted_jewellery_is_reserved_and_hidden_from_catalog(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $this->posPayload($item))
            ->assertOk();

        $this->assertSame(JewelleryItemStatus::RESERVED, $item->fresh()->status);

        $catalog = $this->actingAs($admin)
            ->getJson(route('branch.quotations.search', ['catalog' => 1]))
            ->assertOk()
            ->json('data.items');

        $this->assertFalse(collect($catalog)->contains(fn ($row) => ($row['uuid'] ?? null) === $item->uuid));
    }

    /**
     * The ticket shows the next number of each series without using it up.
     */
    public function test_pos_shows_the_next_numbers_without_taking_them(): void
    {
        [, $admin] = $this->branchAdmin();

        $first = $this->actingAs($admin)->getJson(route('branch.pos.numbers'))
            ->assertOk()
            ->json('data');
        $again = $this->actingAs($admin)->getJson(route('branch.pos.numbers'))->json('data');

        $this->assertSame($first, $again);
        $this->assertMatchesRegularExpression('/000001$/', $first['invoice']);
        $this->assertMatchesRegularExpression('/000001$/', $first['quotation']);
        $this->assertNotSame($first['invoice'], $first['quotation']);

        $this->actingAs($admin)->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee($first['invoice']);
    }

    /**
     * What was shown is what the document gets, and the screen then moves on.
     */
    public function test_saved_documents_take_the_previewed_number_and_the_preview_moves_on(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);
        $second = $this->piece($admin, $purity);

        $shown = $this->actingAs($admin)->getJson(route('branch.pos.numbers'))->json('data');

        $quote = $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $this->posPayload($item))
            ->assertOk();
        $this->assertSame($shown['quotation'], $quote->json('data.number'));
        $this->assertStringContainsString($shown['quotation'], $quote->json('message'));

        $invoice = $this->actingAs($admin)
            ->postJson(route('branch.quotations.invoice'), $this->posPayload($second, ['payment_method' => 'cash']))
            ->assertOk();
        // A direct invoice never uses a quotation number.
        $this->assertSame($shown['invoice'], $invoice->json('data.number'));

        $next = $this->actingAs($admin)->getJson(route('branch.pos.numbers'))->json('data');
        $this->assertNotSame($shown['quotation'], $next['quotation']);
        $this->assertNotSame($shown['invoice'], $next['invoice']);
    }

    public function test_every_new_document_button_opens_the_pos_on_that_document(): void
    {
        [, $admin] = $this->branchAdmin();

        $expect = [
            'branch.invoices.index' => 'invoice',
            'branch.quotations.index' => 'quotation',
            'branch.delivery-notes.index' => 'delivery_note',
        ];

        foreach ($expect as $list => $kind) {
            $this->actingAs($admin)->get(route($list))
                ->assertOk()
                ->assertSee(route('branch.pos.show', ['kind' => $kind]), false);

            $page = $this->actingAs($admin)->get(route('branch.pos.show', ['kind' => $kind]))->assertOk()->getContent();
            $this->assertStringContainsString('&quot;defaultKind&quot;:&quot;'.$kind.'&quot;', $page, "POS did not open on {$kind}");
        }
    }
}
