<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\QuotationStatus;
use App\Enums\StoneMovementType;
use App\Enums\StoneOrigin;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\Purity;
use App\Models\Quotation;
use App\Models\Stone;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\InvoiceConversionService;
use App\Services\StoneStockService;
use Database\Seeders\MetalPuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ModuleFiveSmokeTest
 *
 * Renders every Module 5 screen for SuperAdmin and branch Admin so blade
 * syntax errors, missing view variables, and broken policies surface here
 * instead of as runtime 500s in the browser.
 */
class ModuleFiveSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->seed(MetalPuritySeeder::class);
    }

    /**
     * SuperAdmin account (factory default role).
     */
    private function superAdmin(): User
    {
        return User::factory()->create();
    }

    /**
     * Branch Admin with a live branch and full branch permission set.
     */
    private function branchAdmin(): User
    {
        $branch = Branch::factory()->create();

        return User::factory()->admin($branch->id)->create();
    }

    /**
     * First seeded gold purity (22K).
     */
    private function purity(): Purity
    {
        return Purity::query()->where('name', '22K')->firstOrFail();
    }

    public function test_superadmin_gold_rates_page_renders(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.gold-rates.index'))
            ->assertOk();
    }

    public function test_superadmin_can_store_gold_rates(): void
    {
        $purity = $this->purity();

        $this->actingAs($this->superAdmin())
            ->post(route('superadmin.gold-rates.store'), [
                'effective_date' => now()->toDateString(),
                'rates' => [$purity->id => '9500.50'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('superadmin.gold-rates.index'));

        $this->assertDatabaseHas('gold_rates', [
            'purity_id' => $purity->id,
            'rate_per_gram' => '9500.50',
        ]);
    }

    public function test_branch_admin_cannot_store_gold_rates(): void
    {
        $purity = $this->purity();

        $this->actingAs($this->branchAdmin())
            ->post(route('superadmin.gold-rates.store'), [
                'effective_date' => now()->toDateString(),
                'rates' => [$purity->id => '9500.50'],
            ])
            ->assertForbidden();
    }

    public function test_branch_gold_rates_page_renders(): void
    {
        $this->actingAs($this->branchAdmin())
            ->get(route('branch.gold-rates.index'))
            ->assertOk();
    }

    public function test_branch_module_five_index_pages_render(): void
    {
        $admin = $this->branchAdmin();

        foreach (['branch.customers.index', 'branch.quotations.index', 'branch.invoices.index'] as $routeName) {
            $this->actingAs($admin)->get(route($routeName))->assertOk();
        }
    }

    public function test_branch_create_screens_render(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)->get(route('branch.customers.create'))->assertOk();
        $this->actingAs($admin)
            ->get(route('branch.quotations.create'))
            ->assertRedirect(route('branch.pos.show', ['kind' => 'quotation']));
        $this->actingAs($admin)
            ->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('Generate Quotation')
            ->assertSee('Generate Invoice');
    }

    public function test_customer_detail_and_edit_screens_render(): void
    {
        $admin = $this->branchAdmin();

        $customer = app(CustomerService::class)->create([
            'name' => 'Test Customer',
            'phone' => '03001234567',
        ], $admin);

        $this->actingAs($admin)->get(route('branch.customers.show', $customer))->assertOk();
        $this->actingAs($admin)->get(route('branch.customers.edit', $customer))->assertOk();
    }

    /**
     * Select2 AJAX needs a JSON list. A keyed object makes the widget hang
     * on "Searching…" because Array.map is not a function.
     */
    public function test_customer_search_returns_a_json_list(): void
    {
        $admin = $this->branchAdmin();
        app(CustomerService::class)->create([
            'name' => 'Amina Khan',
            'phone' => '03001112233',
        ], $admin);

        $this->actingAs($admin)
            ->getJson(route('branch.customers.search', ['q' => 'Amina']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customers.0.name', 'Amina Khan');

        $customers = $this->actingAs($admin)
            ->getJson(route('branch.customers.search'))
            ->json('data.customers');

        $this->assertIsList($customers);
        $this->assertNotEmpty($customers);
    }

    public function test_quotation_detail_and_print_screens_render(): void
    {
        $admin = $this->branchAdmin();
        $quotation = $this->draftQuotation($admin);

        $this->actingAs($admin)->get(route('branch.quotations.show', $quotation))->assertOk();
        $this->actingAs($admin)->get(route('branch.quotations.print', $quotation))->assertOk();
        $this->actingAs($admin)->get(route('branch.quotations.edit', $quotation))->assertOk();
    }

    public function test_invoice_detail_print_and_public_verify_render(): void
    {
        $admin = $this->branchAdmin();
        $quotation = $this->draftQuotation($admin);

        $invoice = app(InvoiceConversionService::class)->convert($quotation, [
            'payment_method' => 'cash',
        ], $admin);

        $this->actingAs($admin)->get(route('branch.invoices.show', $invoice))->assertOk();
        $this->actingAs($admin)->get(route('branch.invoices.print', $invoice))->assertOk();

        // Public page is unauthenticated and keyed on the verification token.
        $this->get(route('public.invoices.verify', $invoice->verification_token))->assertOk();
    }

    public function test_new_quotation_opens_pos_edit_offers_convert(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)
            ->get(route('branch.quotations.create'))
            ->assertRedirect(route('branch.pos.show', ['kind' => 'quotation']));

        $this->actingAs($admin)
            ->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('Generate Quotation')
            ->assertSee('Generate Invoice')
            ->assertDontSee('Save quotation');

        $quotation = $this->draftQuotation($admin);

        $this->actingAs($admin)
            ->get(route('branch.quotations.edit', $quotation))
            ->assertOk()
            ->assertSee('Save quotation')
            ->assertSee('Convert to invoice')
            ->assertDontSee('Save invoice');
    }

    public function test_direct_invoice_from_workspace_creates_invoice_without_a_quotation(): void
    {
        $admin = $this->branchAdmin();
        $purity = $this->purity();

        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $quotationsBefore = Quotation::query()->count();

        $response = $this->actingAs($admin)->postJson(route('branch.quotations.invoice'), [
            'walkin_name' => 'Walk-in Buyer',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'tax_type' => 'none',
            'payment_method' => 'cash',
            'lines' => [[
                'line_type' => LineType::JEWELLERY_ITEM->value,
                'jewellery_item_id' => $item->id,
                'quantity' => 1,
                'gold_rate_applied' => 1000,
                'making_charge_type' => 'none',
                'making_charge_value' => 0,
            ]],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $uuid = $response->json('data.uuid');
        $this->assertIsString($uuid);
        $this->assertNotEmpty($uuid);

        $this->assertSame($quotationsBefore, Quotation::query()->count());

        $customer = Customer::query()->where('phone', '03001234567')->first();
        $this->assertNotNull($customer);

        $this->assertDatabaseHas('invoices', [
            'branch_id' => $admin->branch_id,
            'customer_id' => $customer->id,
            'quotation_id' => null,
            'status' => InvoiceStatus::COMPLETED->value,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(JewelleryItemStatus::SOLD, $item->fresh()->status);
    }

    public function test_direct_invoice_requires_payment_method(): void
    {
        $admin = $this->branchAdmin();
        $purity = $this->purity();

        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $invoicesBefore = Invoice::query()->count();

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.invoice'), [
                'walkin_name' => 'Walk-in Buyer',
                'walkin_phone' => '03001234567',
                'quotation_date' => now()->toDateString(),
                'discount_type' => 'none',
                'tax_type' => 'none',
                'lines' => [[
                    'line_type' => LineType::JEWELLERY_ITEM->value,
                    'jewellery_item_id' => $item->id,
                    'quantity' => 1,
                    'gold_rate_applied' => 1000,
                    'making_charge_type' => 'none',
                    'making_charge_value' => 0,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);

        $this->assertSame($invoicesBefore, Invoice::query()->count());
        $this->assertSame(JewelleryItemStatus::AVAILABLE, $item->fresh()->status);
    }

    public function test_user_without_convert_cannot_issue_a_direct_invoice(): void
    {
        $admin = $this->branchAdmin();
        $staff = User::factory()->inventoryStaff($admin->branch_id)->create();
        $purity = $this->purity();

        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $this->actingAs($staff)
            ->postJson(route('branch.quotations.invoice'), [
                'walkin_name' => 'Walk-in Buyer',
                'walkin_phone' => '03001234567',
                'quotation_date' => now()->toDateString(),
                'discount_type' => 'none',
                'tax_type' => 'none',
                'payment_method' => 'cash',
                'lines' => [[
                    'line_type' => LineType::JEWELLERY_ITEM->value,
                    'jewellery_item_id' => $item->id,
                    'quantity' => 1,
                    'gold_rate_applied' => 1000,
                    'making_charge_type' => 'none',
                    'making_charge_value' => 0,
                ]],
            ])
            ->assertForbidden();

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(JewelleryItemStatus::AVAILABLE, $item->fresh()->status);
    }

    public function test_draft_quotation_can_still_be_converted_to_invoice(): void
    {
        $admin = $this->branchAdmin();
        $quotation = $this->draftQuotation($admin);

        $this->actingAs($admin)
            ->post(route('branch.quotations.convert', $quotation), [
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => QuotationStatus::CONVERTED->value,
        ]);

        $this->assertDatabaseHas('invoices', [
            'quotation_id' => $quotation->id,
            'status' => InvoiceStatus::COMPLETED->value,
        ]);
    }

    public function test_branch_admin_can_store_a_walkin_quotation(): void
    {
        $admin = $this->branchAdmin();
        $purity = $this->purity();

        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $response = $this->actingAs($admin)->postJson(route('branch.quotations.store'), [
            'walkin_name' => 'Walk-in Buyer',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'tax_type' => 'none',
            'lines' => [[
                'line_type' => LineType::JEWELLERY_ITEM->value,
                'jewellery_item_id' => $item->id,
                'item_code_snapshot' => $item->item_code,
                'name_snapshot' => $item->name,
                'net_weight_snapshot' => $item->net_weight,
                'quantity' => 1,
                'gold_rate_applied' => 1000,
                'making_charge_type' => 'none',
                'making_charge_value' => 0,
            ]],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $customer = Customer::query()->where('phone', '03001234567')->first();
        $this->assertNotNull($customer);
        $this->assertSame('Walk-in Buyer', $customer->name);

        $this->assertDatabaseHas('quotations', [
            'branch_id' => $admin->branch_id,
            'customer_id' => $customer->id,
            'walkin_name' => null,
            'walkin_phone' => null,
            'status' => QuotationStatus::DRAFT->value,
        ]);
    }

    public function test_walkin_quotation_reuses_customer_with_the_same_phone(): void
    {
        $admin = $this->branchAdmin();
        $purity = $this->purity();
        $existing = Customer::factory()->create([
            'name' => 'Registered Buyer',
            'phone' => '03009876543',
        ]);

        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $this->actingAs($admin)->postJson(route('branch.quotations.store'), [
            'walkin_name' => 'Someone Else',
            'walkin_phone' => '03009876543',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'tax_type' => 'none',
            'lines' => [[
                'line_type' => LineType::JEWELLERY_ITEM->value,
                'jewellery_item_id' => $item->id,
                'quantity' => 1,
                'gold_rate_applied' => 1000,
                'making_charge_type' => 'none',
                'making_charge_value' => 0,
            ]],
        ])->assertOk();

        $this->assertSame(1, Customer::query()->where('phone', '03009876543')->count());
        $this->assertSame('Registered Buyer', $existing->fresh()->name);
        $this->assertDatabaseHas('quotations', [
            'customer_id' => $existing->id,
            'walkin_name' => null,
        ]);
    }

    /**
     * The workspace posts the whole line object. A jewellery line often
     * carries stone_id=null or a leftover uuid; that must not 422.
     */
    public function test_jewellery_line_ignores_null_or_uuid_stone_id(): void
    {
        $admin = $this->branchAdmin();
        $purity = $this->purity();

        $first = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);
        $second = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $payload = [
            'walkin_name' => 'Walk-in Buyer',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'tax_type' => 'none',
            'lines' => [[
                'line_type' => LineType::JEWELLERY_ITEM->value,
                'jewellery_item_id' => $first->id,
                'jewellery_item_uuid' => $first->uuid,
                'stone_id' => $first->uuid,
                'quantity' => 1,
                'gold_rate_applied' => 1000,
                'making_charge_type' => 'none',
                'making_charge_value' => 0,
            ]],
        ];

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $payload['lines'][0]['jewellery_item_id'] = $second->id;
        $payload['lines'][0]['jewellery_item_uuid'] = $second->uuid;
        $payload['lines'][0]['stone_id'] = null;

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), $payload)
            ->assertOk();
    }

    /**
     * Loose stone lines may identify the packet by uuid (browser JSON).
     */
    public function test_loose_stone_line_accepts_stone_uuid(): void
    {
        $admin = $this->branchAdmin();
        $stone = Stone::factory()->create([
            'branch_id' => $admin->branch_id,
            'is_active' => true,
        ]);
        app(StoneStockService::class)->apply($stone, StoneMovementType::INTAKE, 5, $admin);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), [
                'walkin_name' => 'Walk-in Buyer',
                'walkin_phone' => '03001234567',
                'quotation_date' => now()->toDateString(),
                'discount_type' => 'none',
                'tax_type' => 'none',
                'lines' => [[
                    'line_type' => LineType::LOOSE_STONE->value,
                    'stone_id' => $stone->uuid,
                    'stone_uuid' => $stone->uuid,
                    'quantity' => 1,
                    'price_per_piece' => 500,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('quotation_items', [
            'stone_id' => $stone->id,
            'line_type' => LineType::LOOSE_STONE->value,
        ]);
    }

    public function test_stone_snapshots_are_rebuilt_from_the_allocation_not_the_request(): void
    {
        $admin = $this->branchAdmin();
        $purity = $this->purity();

        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        // The catalog stone the piece was set with; the allocation snapshot is
        // what the document must print, not the catalog row.
        $catalogStone = Stone::factory()->create(['branch_id' => $admin->branch_id]);

        $allocation = $item->itemStones()->create([
            'stone_id' => $catalogStone->id,
            'quantity' => 2,
            'stone_code_snapshot' => 'STN-000009',
            'stone_name_snapshot' => 'Green Emerald',
            'carat_snapshot' => 7.0,
            'stone_type_snapshot' => 'Emerald',
            'origin_snapshot' => StoneOrigin::NATURAL->value,
            'total_carat' => 14.0,
        ]);

        $this->actingAs($admin)->postJson(route('branch.quotations.store'), [
            'walkin_name' => 'Walk-in Buyer',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'tax_type' => 'none',
            'lines' => [[
                'line_type' => LineType::JEWELLERY_ITEM->value,
                'jewellery_item_id' => $item->id,
                'quantity' => 1,
                'gold_rate_applied' => 1000,
                'making_charge_type' => 'none',
                'making_charge_value' => 0,
                'stones' => [[
                    'jewellery_item_stone_id' => $allocation->id,
                    'price_per_piece' => 9000,
                    // Deliberately bogus: the server must ignore client-supplied
                    // descriptive fields and read the allocation instead.
                    'stone_name_snapshot' => 'Fake Stone',
                    'carat_snapshot' => 99,
                ]],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('quotation_item_stones', [
            'jewellery_item_stone_id' => $allocation->id,
            'stone_name_snapshot' => 'Green Emerald',
            'stone_type_snapshot' => 'Emerald',
            'carat_snapshot' => 7.000,
            'quantity' => 2,
            'price_per_piece' => 9000.00,
            'total_price' => 18000.00,
        ]);

        $this->assertDatabaseMissing('quotation_item_stones', [
            'stone_name_snapshot' => 'Fake Stone',
        ]);

        // The line snapshot is rebuilt too, so purity and weight are never blank.
        $this->assertDatabaseHas('quotation_items', [
            'jewellery_item_id' => $item->id,
            'item_code_snapshot' => $item->item_code,
            'name_snapshot' => $item->name,
            'purity_snapshot' => $purity->name,
        ]);
    }

    public function test_quotation_without_customer_or_walkin_name_is_rejected(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), [
                'quotation_date' => now()->toDateString(),
                'discount_type' => 'none',
                'tax_type' => 'none',
                'lines' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['walkin_name', 'walkin_phone', 'lines']);
    }

    public function test_walkin_quotation_without_phone_is_rejected(): void
    {
        $admin = $this->branchAdmin();
        $purity = $this->purity();

        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $this->actingAs($admin)
            ->postJson(route('branch.quotations.store'), [
                'walkin_name' => 'Walk-in Buyer',
                'quotation_date' => now()->toDateString(),
                'discount_type' => 'none',
                'tax_type' => 'none',
                'lines' => [[
                    'line_type' => LineType::JEWELLERY_ITEM->value,
                    'jewellery_item_id' => $item->id,
                    'quantity' => 1,
                    'gold_rate_applied' => 1000,
                    'making_charge_type' => 'none',
                    'making_charge_value' => 0,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['walkin_phone']);
    }

    /**
     * Convertible draft quotation holding one available jewellery piece.
     */
    private function draftQuotation(User $admin): Quotation
    {
        $purity = $this->purity();

        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $quotation = Quotation::query()->create([
            'branch_id' => $admin->branch_id,
            'quotation_number' => 'QTN-000901',
            'quotation_date' => now(),
            'status' => QuotationStatus::DRAFT,
            'walkin_name' => 'Walk-in Buyer',
            'subtotal' => 5000,
            'grand_total' => 5000,
            'discount_type' => 'none',
            'tax_type' => 'none',
            'notes' => 'Smoke test quotation.',
        ]);

        $quotation->items()->create([
            'line_type' => LineType::JEWELLERY_ITEM,
            'jewellery_item_id' => $item->id,
            'item_code_snapshot' => $item->item_code,
            'name_snapshot' => $item->name,
            'purity_snapshot' => $purity->name,
            'net_weight_snapshot' => $item->net_weight,
            'quantity' => 1,
            'gold_rate_applied' => 1000,
            'gold_value' => 5000,
            'making_charge_type' => 'none',
            'making_charges' => 0,
            'stone_value' => 0,
            'line_total' => 5000,
        ]);

        return $quotation->fresh(['items']);
    }
}
