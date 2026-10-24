<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\QuotationStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\GoldRate;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\MetalType;
use App\Models\Purity;
use App\Models\Quotation;
use App\Models\Stone;
use App\Models\User;
use App\Services\GoldRateService;
use App\Services\InvoiceConversionService;
use App\Services\PricingCalculationService;
use Database\Seeders\MetalPuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module 5: gold rates, customers, quotations, invoices, and stock rules.
 */
class ModuleFiveTest extends TestCase
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
     * Tax applies after discount — verified with a worked example.
     */
    public function test_pricing_tax_applies_after_discount(): void
    {
        $service = app(PricingCalculationService::class);

        $lines = [[
            'line_type' => LineType::LOOSE_STONE->value,
            'quantity' => 1,
            'price_per_piece' => '1000.00',
        ]];

        $result = $service->recalculate($lines, [
            'discount_type' => 'percentage',
            'discount_value' => '10',
            'tax_type' => 'percentage',
            'tax_rate' => '5',
        ]);

        // Subtotal 1000, discount 10% = 100, after = 900, tax 5% = 45, grand = 945
        $this->assertSame('1000.00', $result['document']['subtotal']);
        $this->assertSame('100.00', $result['document']['discount_amount']);
        $this->assertSame('45.00', $result['document']['tax_amount']);
        $this->assertSame('945.00', $result['document']['grand_total']);
    }

    /**
     * Loose stones are qty × rate. Rate is per piece, not the line total.
     */
    public function test_loose_stone_line_multiplies_quantity_by_rate(): void
    {
        $service = app(PricingCalculationService::class);

        $line = $service->calculateLine([
            'line_type' => LineType::LOOSE_STONE->value,
            'quantity' => 3,
            'price_per_piece' => '20.00',
        ]);

        $this->assertSame(3, $line['quantity']);
        $this->assertSame('60.00', $line['stone_value']);
        $this->assertSame('60.00', $line['line_total']);
    }

    /**
     * Gold rate history is insert-only; latest effective_date wins.
     */
    public function test_gold_rate_insert_only_history(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $super = User::factory()->create();

        $service = app(GoldRateService::class);
        $service->storeRates([$purity->id => 10000], now()->subDay()->toDateString(), $super);
        $service->storeRates([$purity->id => 12000], now()->toDateString(), $super);

        $this->assertSame(2, GoldRate::query()->where('purity_id', $purity->id)->count());

        $map = app(\App\Repositories\GoldRateRepository::class)->currentRateMap();
        $this->assertSame('12000.00', $map[$purity->id]);
    }

    /**
     * Customers are global — branch B can edit customer created in branch A context.
     */
    public function test_customer_phone_unique_system_wide(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $adminA = User::factory()->admin($branchA->id)->create();
        $adminB = User::factory()->admin($branchB->id)->create();

        $this->actingAs($adminA)->post(route('branch.customers.store'), [
            'name' => 'Ali Khan',
            'phone' => '03001234567',
        ])->assertRedirect();

        $this->actingAs($adminB)->post(route('branch.customers.store'), [
            'name' => 'Duplicate',
            'phone' => '03001234567',
        ])->assertSessionHasErrors('phone');

        $customer = Customer::query()->first();
        $this->actingAs($adminB)->put(route('branch.customers.update', $customer), [
            'name' => 'Ali Khan Updated',
            'phone' => '03001234567',
        ])->assertRedirect();

        $this->assertSame('Ali Khan Updated', $customer->fresh()->name);
    }

    /**
     * Selling jewellery with allocated stones must NOT deduct loose stone stock again.
     */
    public function test_invoice_does_not_double_deduct_stones_in_jewellery_piece(): void
    {
        [$branch, $admin, $purity] = $this->branchAdmin();
        $category = Category::factory()->create();
        $metalId = (int) MetalType::query()->where('name', 'Gold')->value('id');

        $this->actingAs($admin)->post(route('branch.stones.store'), [
            'name' => 'Test diamond',
            'shape' => 'ROUND',
            'pricing_mode' => 'per_piece',
            'status' => 'in_stock',
            'price_per_piece' => '100',
            // Registering the lot books its pieces straight into stock.
            'pieces' => 10,
        ]);
        $stone = Stone::query()->first();
        $this->assertSame(10, (int) $stone->quantity);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), [
            'category_id' => $category->id,
            'name' => 'Ring with stone',
            'metal_type_id' => $metalId,
            'purity_id' => $purity->id,
            'gross_weight' => '5.000',
            'metal_weight' => '4.800',
            'status' => JewelleryItemStatus::AVAILABLE->value,
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 2]],
        ])->assertRedirect();

        $qtyAfterAllocation = (int) $stone->fresh()->quantity;
        $this->assertSame(8, $qtyAfterAllocation);

        $item = JewelleryItem::query()->first();
        $quotation = $this->createQuotationWithJewelleryLine($admin, $item, $purity);

        app(InvoiceConversionService::class)->convert($quotation, [
            'payment_method' => 'cash',
        ], $admin);

        $this->assertSame(8, (int) $stone->fresh()->quantity);
        $this->assertSame(JewelleryItemStatus::SOLD, $item->fresh()->status);
    }

    /**
     * SuperAdmin cannot access customer routes (no permission).
     */
    public function test_superadmin_blocked_from_customers(): void
    {
        $super = User::factory()->create();

        $this->actingAs($super)->get(route('branch.customers.index'))->assertForbidden();
    }

    /**
     * Public verify uses token not uuid.
     */
    public function test_public_verification_by_token_only(): void
    {
        [$branch, $admin] = $this->branchAdmin();
        $invoice = Invoice::query()->create([
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-000001',
            'verification_token' => 'verify-token-abc',
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'customer_name_snapshot' => 'Test',
            'invoice_date' => now(),
            'payment_method' => 'cash',
            'status' => InvoiceStatus::COMPLETED,
            'grand_total' => 100,
            'subtotal' => 100,
        ]);

        $this->get(route('public.invoices.verify', 'verify-token-abc'))->assertOk();
        $this->get(route('public.invoices.verify', $invoice->uuid))->assertNotFound();
    }

    /**
     * Quotation workspace can add by name search as well as barcode.
     */
    public function test_quotation_search_finds_available_jewellery_by_name(): void
    {
        [$branch, $admin, $purity] = $this->branchAdmin();
        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'metal_type_id' => $purity->metal_type_id,
            'name' => 'Celeste Halo Band',
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $this->actingAs($admin)
            ->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('Add items');

        $this->actingAs($admin)
            ->getJson(route('branch.quotations.search', ['q' => 'halo']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.uuid', $item->uuid)
            ->assertJsonPath('data.items.0.line.jewellery_item_id', $item->id);

        $catalog = $this->actingAs($admin)
            ->getJson(route('branch.quotations.search', ['catalog' => 1]))
            ->assertOk()
            ->json('data.items');

        $this->assertIsList($catalog);
        $this->assertNotEmpty($catalog);
        $this->assertTrue(collect($catalog)->contains(fn ($row) => ($row['uuid'] ?? null) === $item->uuid));
    }

    /**
     * Helper: minimal quotation with one jewellery line ready to convert.
     */
    private function createQuotationWithJewelleryLine(User $admin, JewelleryItem $item, Purity $purity): Quotation
    {
        $quotation = Quotation::query()->create([
            'branch_id' => $admin->branch_id,
            'quotation_number' => 'QTN-000001',
            'quotation_date' => now(),
            'status' => QuotationStatus::DRAFT,
            'subtotal' => 5000,
            'grand_total' => 5000,
            'discount_type' => 'none',
            'tax_type' => 'none',
        ]);

        $quotation->items()->create([
            'line_type' => LineType::JEWELLERY_ITEM,
            'jewellery_item_id' => $item->id,
            'item_code_snapshot' => $item->item_code,
            'name_snapshot' => $item->name,
            'purity_snapshot' => $purity->name,
            'net_weight_snapshot' => $item->net_weight,
            'quantity' => 1,
            'gold_rate_applied' => 10000,
            'gold_value' => 4000,
            'making_charge_type' => 'none',
            'making_charges' => 0,
            'stone_value' => 1000,
            'line_total' => 5000,
        ]);

        return $quotation->fresh(['items']);
    }
}
