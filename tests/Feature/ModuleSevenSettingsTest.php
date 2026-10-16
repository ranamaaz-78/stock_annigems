<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\QuotationStatus;
use App\Enums\SettingGroup;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\MetalType;
use App\Models\Purity;
use App\Models\Setting;
use App\Models\SettingHistory;
use App\Models\Stone;
use App\Models\User;
use App\Services\BranchSettingService;
use App\Services\InvoiceConversionService;
use App\Services\SettingService;
use App\Support\Settings\SettingCatalog;
use Database\Seeders\MetalPuritySeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ModuleSevenSettingsTest
 *
 * Load-bearing guarantees of the settings layer:
 *
 * - SuperAdmin writes global groups; Admin / Manager / Cashier cannot
 * - the seeder never overwrites a live value
 * - cache forgets on write so the next read is the new typed value
 * - feature toggles stop new generation only — existing tokens still resolve
 * - invoice money stays on the snapshot after the current tax rate changes
 * - non-Admin discount is capped by name; Admin may exceed
 * - numbering prefix changes leave issued codes alone
 * - a starting number below the highest issued is raised, not refused
 * - a gold-rate jump over the configured warning percent needs confirm_deviation
 * - unknown keys and cross-branch writes are refused
 * - history and audit rows carry the actor's role snapshot
 */
class ModuleSevenSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->seed(MetalPuritySeeder::class);

        $this->branch = Branch::factory()->create(['name' => 'Main Showroom']);
        $this->admin = User::factory()->admin($this->branch->id)->create(['name' => 'Branch Admin']);
        $this->superAdmin = User::factory()->create(['name' => 'Super Admin']);

        RateLimiter::clear('public-item:127.0.0.1');
        RateLimiter::clear('invoice-verify:127.0.0.1');
    }

    /**
     * SuperAdmin can save a global group and read the new typed value.
     */
    public function test_superadmin_can_save_a_global_group(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('superadmin.settings.update', 'company'), $this->groupPayload(SettingGroup::COMPANY, [
                'company_name' => 'Annigems Gold House',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('superadmin.settings.index'));

        $this->assertSame('Annigems Gold House', setting('company_name'));
    }

    /**
     * Admin cannot write SuperAdmin settings (middleware + policy).
     */
    public function test_admin_cannot_put_global_settings(): void
    {
        $this->actingAs($this->admin)
            ->put(route('superadmin.settings.update', 'company'), $this->groupPayload(SettingGroup::COMPANY))
            ->assertForbidden();
    }

    /**
     * Cashier cannot open the Admin read-only global mirror.
     */
    public function test_cashier_cannot_view_global_settings(): void
    {
        $cashier = User::factory()->cashier($this->branch->id)->create();

        $this->actingAs($cashier)
            ->get(route('branch.company-settings.show'))
            ->assertForbidden();
    }

    /**
     * Manager may view branch settings but cannot write them.
     */
    public function test_manager_can_view_but_not_put_branch_settings(): void
    {
        $manager = User::factory()->manager($this->branch->id)->create();

        $this->actingAs($manager)
            ->get(route('branch.settings.index'))
            ->assertOk();

        $this->actingAs($manager)
            ->put(route('branch.settings.update', 'print'), $this->branchPrintPayload())
            ->assertForbidden();
    }

    /**
     * Admin of branch A cannot write branch B through the service.
     */
    public function test_admin_cannot_write_another_branchs_settings(): void
    {
        $other = Branch::factory()->create();

        $this->expectException(ValidationException::class);

        app(BranchSettingService::class)->setGroup(
            $other->id,
            SettingGroup::PRINT,
            ['label_size' => 'large'],
            $this->admin
        );
    }

    /**
     * Re-seeding must not reset a value SuperAdmin already changed.
     */
    public function test_seeder_is_idempotent_and_does_not_overwrite(): void
    {
        $this->actingAs($this->superAdmin);
        app(SettingService::class)->setGroup(SettingGroup::LOCALE, [
            'currency_symbol' => 'Rs.',
        ], $this->superAdmin);

        $this->seed(SettingSeeder::class);

        $this->assertSame('Rs.', setting('currency_symbol'));
        $this->assertSame(count(SettingCatalog::definitions()), Setting::query()->count());
    }

    /**
     * Cache forgets inside the write so the next get() is the new typed value.
     */
    public function test_cache_invalidates_on_write(): void
    {
        $this->assertTrue((bool) setting('tax_enabled'));

        $this->actingAs($this->superAdmin);
        app(SettingService::class)->setGroup(SettingGroup::TAX, [
            'tax_enabled' => false,
        ], $this->superAdmin);

        $this->assertFalse((bool) setting('tax_enabled'));
    }

    /**
     * Disabling stone barcodes leaves existing barcodes scannable and
     * issues no barcode on the next registration.
     */
    public function test_stone_barcode_toggle_stops_generation_only(): void
    {
        $existing = $this->registerStone($this->admin, 'Existing packet', '0.200');
        $this->assertNotNull($existing->barcode);

        $this->actingAs($this->superAdmin);
        app(SettingService::class)->setGroup(SettingGroup::FEATURES, [
            'stone_barcode_enabled' => false,
        ], $this->superAdmin);

        $fresh = $this->registerStone($this->admin, 'New packet', '0.350');
        $this->assertNull($fresh->barcode);
        // The STN code is still issued; only the packet label is suppressed.
        $this->assertSame('STN-000002', $fresh->stone_code);

        $this->actingAs($this->admin)
            ->get(route('branch.stones.show', $fresh))
            ->assertOk()
            ->assertDontSee('Packet barcode', false)
            ->assertSee('No packet barcode was issued');

        $this->actingAs($this->admin)
            ->postJson(route('branch.stone-intakes.lookup'), ['code' => $existing->barcode])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Jewellery QR / barcode toggles skip new tokens; an existing public
     * page still resolves after both QR and public-product are off.
     */
    public function test_jewellery_qr_toggle_stops_generation_only(): void
    {
        $existing = $this->registerJewellery($this->admin, 'Existing ring');
        $this->assertNotNull($existing->qr_token);
        $this->assertNotNull($existing->barcode);

        $this->actingAs($this->superAdmin);
        app(SettingService::class)->setGroup(SettingGroup::FEATURES, [
            'jewellery_barcode_enabled' => false,
            'jewellery_qr_enabled' => false,
            'public_product_page_enabled' => false,
        ], $this->superAdmin);

        $fresh = $this->registerJewellery($this->admin, 'New ring');
        $this->assertNull($fresh->barcode);
        $this->assertNull($fresh->qr_token);

        $this->get(route('public.jewellery-items.show', $existing->qr_token))->assertOk();
    }

    /**
     * Invoice verification stays up for tokens issued before the toggle.
     */
    public function test_invoice_qr_toggle_stops_generation_only(): void
    {
        $item = $this->registerJewellery($this->admin, 'Sale ring');
        $invoice = $this->convertJewellery($this->admin, $item);
        $this->assertNotNull($invoice->verification_token);

        $this->actingAs($this->superAdmin);
        app(SettingService::class)->setGroup(SettingGroup::FEATURES, [
            'invoice_qr_enabled' => false,
            'public_invoice_verification_enabled' => false,
        ], $this->superAdmin);

        $nextItem = $this->registerJewellery($this->admin, 'Later ring');
        $nextInvoice = $this->convertJewellery($this->admin, $nextItem);
        $this->assertNull($nextInvoice->verification_token);

        $this->get(route('public.invoices.verify', $invoice->verification_token))->assertOk();
    }

    /**
     * Changing the current tax rate must not rewrite a stored invoice.
     */
    public function test_historical_invoice_keeps_snapshot_tax_after_rate_change(): void
    {
        $invoice = Invoice::query()->create([
            'branch_id' => $this->branch->id,
            'invoice_number' => 'INV-HIST-1',
            'verification_token' => 'hist-verify-token',
            'customer_name_snapshot' => 'Historical buyer',
            'invoice_date' => now()->toDateString(),
            'subtotal' => 1000,
            'discount_type' => 'none',
            'discount_value' => 0,
            'discount_amount' => 0,
            'tax_type' => 'percentage',
            'tax_rate' => 5,
            'tax_amount' => 77.77,
            'grand_total' => 1077.77,
            'payment_method' => 'cash',
            'status' => InvoiceStatus::COMPLETED,
        ]);

        $this->actingAs($this->superAdmin);
        $this->useTax(true, 17);

        $this->actingAs($this->admin)
            ->get(route('branch.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('77.77', false);

        $this->assertSame('77.77', (string) $invoice->fresh()->tax_amount);
        $this->assertSame('5.00', (string) $invoice->fresh()->tax_rate);
    }

    /**
     * Over the ceiling is 422 for Manager and Admin — the setting is shop-wide.
     */
    public function test_discount_ceiling_applies_to_every_role(): void
    {
        $this->actingAs($this->superAdmin);
        app(SettingService::class)->setGroup(SettingGroup::DISCOUNT, [
            'discount_enabled' => true,
            'max_discount_percentage' => 10,
        ], $this->superAdmin);

        $manager = User::factory()->manager($this->branch->id)->create();
        $item = JewelleryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'purity_id' => $this->purity()->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $payload = $this->quotationPayload($item, [
            'discount_type' => 'percentage',
            'discount_value' => 20,
        ]);

        $managerDenied = $this->actingAs($manager)
            ->postJson(route('branch.quotations.store'), $payload);
        $managerDenied->assertStatus(422);
        $this->assertStringContainsString('10%', (string) $managerDenied->json('errors.discount_value.0'));

        $adminDenied = $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $payload);
        $adminDenied->assertStatus(422);
        $this->assertStringContainsString('10%', (string) $adminDenied->json('errors.discount_value.0'));

        $allowed = $this->quotationPayload($item, [
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $allowed)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * SuperAdmin tax type/rate is written onto the quotation even when the
     * form posts tax_type=none. Cashiers cannot pick a different GST.
     */
    public function test_settings_tax_is_applied_and_cannot_be_overridden(): void
    {
        $this->actingAs($this->superAdmin);
        $this->useTax(true, 18);

        $item = JewelleryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'purity_id' => $this->purity()->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $this->quotationPayload($item, [
                'tax_type' => 'none',
                'tax_rate' => 0,
            ]))
            ->assertOk();

        $quotation = \App\Models\Quotation::query()->latest('id')->first();
        $this->assertNotNull($quotation);
        $this->assertSame('percentage', $quotation->tax_type->value);
        $this->assertSame('18.00', (string) $quotation->tax_rate);
        $this->assertGreaterThan(0, (float) $quotation->tax_amount);

        $this->actingAs($this->admin)
            ->get(route('branch.quotations.create'))
            ->assertRedirect(route('branch.pos.show', ['kind' => 'quotation']));

        $this->actingAs($this->admin)
            ->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('IVA 18%')
            ->assertDontSee('id="tax-type"', false);
    }

    /**
     * Tax off strips tax from a new quotation even if the payload sends one.
     */
    public function test_disabled_tax_is_stripped_from_quotation(): void
    {
        $this->actingAs($this->superAdmin);
        $this->useTax(false, 18);

        $item = JewelleryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'purity_id' => $this->purity()->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $this->quotationPayload($item, [
                'tax_type' => 'percentage',
                'tax_rate' => 18,
            ]))
            ->assertOk();

        $quotation = \App\Models\Quotation::query()->latest('id')->first();
        $this->assertSame('none', $quotation->tax_type->value);
        $this->assertSame('0.00', (string) $quotation->tax_amount);
    }

    /**
     * A prefix change leaves issued codes untouched; the next code uses it.
     */
    public function test_prefix_change_leaves_existing_codes_and_applies_to_next(): void
    {
        $first = $this->registerJewellery($this->admin, 'First ring');
        $this->assertStringStartsWith('JWL-', $first->item_code);

        $this->actingAs($this->superAdmin);
        app(SettingService::class)->setGroup(SettingGroup::NUMBERING, [
            'numbering_jewellery_prefix' => 'GEM-',
        ], $this->superAdmin);

        $second = $this->registerJewellery($this->admin, 'Second ring');
        $this->assertSame($first->item_code, $first->fresh()->item_code);
        $this->assertStringStartsWith('GEM-', $second->item_code);
    }

    /**
     * A start at or below the highest issued number is clamped, and the
     * rest of the numbering group (prefixes) still saves.
     */
    public function test_starting_number_below_highest_issued_does_not_block_prefixes(): void
    {
        DB::table('code_sequences')->updateOrInsert(
            ['key' => 'jewellery'],
            ['last_number' => 50, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->actingAs($this->superAdmin)
            ->put(route('superadmin.settings.update', 'numbering'), $this->groupPayload(SettingGroup::NUMBERING, [
                'numbering_jewellery_prefix' => 'GEM-',
                'numbering_jewellery_start' => 10,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('GEM-', setting('numbering_jewellery_prefix'));
        $this->assertSame(50, (int) DB::table('code_sequences')->where('key', 'jewellery')->value('last_number'));

        $next = $this->registerJewellery($this->admin, 'After clamp');
        $this->assertSame('GEM-000051', $next->item_code);
    }

    /**
     * The live shop hit this: CUSTOMER- plus a start below the highest
     * issued customer number used to 422 the whole Numbering group, so
     * prefixes never persisted. Clamp and save the prefix.
     */
    public function test_customer_prefix_saves_when_start_is_below_highest_issued(): void
    {
        DB::table('code_sequences')->updateOrInsert(
            ['key' => 'customer'],
            ['last_number' => 4, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->actingAs($this->superAdmin)
            ->put(route('superadmin.settings.update', 'numbering'), $this->groupPayload(SettingGroup::NUMBERING, [
                'numbering_customer_prefix' => 'CUSTOMER-',
                'numbering_customer_start' => 3,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('CUSTOMER-', setting('numbering_customer_prefix'));
        $this->assertSame(5, (int) setting('numbering_customer_start'));

        $this->actingAs($this->admin);
        $customer = app(\App\Services\CustomerService::class)->create([
            'name' => 'Prefix Check',
            'phone' => '03001112233',
        ], $this->admin);

        $this->assertSame('CUSTOMER-000005', $customer->customer_code);
    }

    /**
     * A gold-rate jump over the threshold without confirm_deviation is rejected.
     */
    public function test_gold_rate_jump_without_confirm_is_rejected(): void
    {
        $purity = $this->purity();

        $this->actingAs($this->superAdmin)
            ->post(route('superadmin.gold-rates.store'), [
                'effective_date' => now()->toDateString(),
                'rates' => [$purity->id => '10000'],
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->superAdmin)
            ->post(route('superadmin.gold-rates.store'), [
                'effective_date' => now()->toDateString(),
                'rates' => [$purity->id => '15000'],
            ])
            ->assertSessionHasErrors('rates');
    }

    /**
     * A key that is not in the catalog cannot be created from a form.
     */
    public function test_unknown_key_in_a_group_payload_is_rejected(): void
    {
        $payload = $this->groupPayload(SettingGroup::FEATURES);
        $payload['not_a_real_key'] = '1';

        $this->actingAs($this->superAdmin)
            ->put(route('superadmin.settings.update', 'features'), $payload)
            ->assertSessionHasErrors('settings');
    }

    /**
     * History and audit rows are written with the actor's role snapshot.
     */
    public function test_history_and_audit_carry_role_snapshot(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('superadmin.settings.update', 'company'), $this->groupPayload(SettingGroup::COMPANY, [
                'company_name' => 'Renamed House',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings_history', [
            'key' => 'company_name',
            'new_value' => 'Renamed House',
            'changed_by' => $this->superAdmin->id,
            'changed_by_role' => UserRole::SUPERADMIN->value,
        ]);

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'setting.updated')
                ->where('user_id', $this->superAdmin->id)
                ->where('user_role', UserRole::SUPERADMIN->value)
                ->exists()
        );

        $this->actingAs($this->superAdmin)
            ->get(route('superadmin.settings.history'))
            ->assertOk()
            ->assertSee('company_name');
    }

    /**
     * SuperAdmin and Admin screens render; Manager sees branch settings.
     */
    public function test_settings_screens_render_for_allowed_roles(): void
    {
        $this->actingAs($this->superAdmin)->get(route('superadmin.settings.index'))->assertOk()
            ->assertDontSee('Gold Rate Change Warning Threshold', false)
            ->assertDontSee('Stone Low Stock Threshold', false)
            ->assertDontSee('Aging Stock Buckets', false)
            ->assertDontSee('Scanner Keystroke Threshold Ms', false)
            ->assertDontSee('Session Timeout Minutes', false)
            ->assertDontSee('Password Min Length', false)
            ->assertDontSee('Login Max Attempts', false)
            ->assertDontSee('Inventory Thresholds', false);
        $this->actingAs($this->admin)->get(route('branch.settings.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('branch.company-settings.show'))->assertOk()
            ->assertSee('These settings are managed by the SuperAdmin')
            ->assertDontSee('Gold Rate Change Warning Threshold', false)
            ->assertDontSee('Stone Low Stock Threshold', false)
            ->assertDontSee('Scanner Keystroke Threshold Ms', false)
            ->assertDontSee('Session Timeout Minutes', false);
    }

    /**
     * Catalog defaults for one group, with HTTP-friendly toggle/json values.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function groupPayload(SettingGroup $group, array $overrides = []): array
    {
        $payload = [];

        foreach (SettingCatalog::forGroup($group) as $definition) {
            if (($definition['widget'] ?? '') === 'file') {
                continue;
            }

            $value = $overrides[$definition['key']] ?? $definition['default'];

            if (($definition['widget'] ?? '') === 'toggle') {
                $payload[$definition['key']] = $value ? '1' : '0';
            } elseif (($definition['widget'] ?? '') === 'json_list') {
                $payload[$definition['key']] = is_array($value) ? implode(', ', $value) : $value;
            } else {
                $payload[$definition['key']] = $value;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function branchPrintPayload(): array
    {
        return [
            'label_size' => 'medium',
            'labels_per_sheet' => 1,
            'invoice_print_format' => 'a4',
            'quotation_print_format' => 'a4',
            'include_qr_on_label' => '1',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function quotationPayload(JewelleryItem $item, array $overrides = []): array
    {
        return array_merge([
            'walkin_name' => 'Walk-in Buyer',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'discount_value' => 0,
            'tax_type' => 'none',
            'tax_rate' => 0,
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

    /**
     * Register a stone through the real HTTP path so CodeSequenceService runs.
     */
    private function registerStone(User $actor, string $name, string $carat = '0.200'): Stone
    {
        $this->actingAs($actor)->post(route('branch.stones.store'), [
            'name' => $name,
            'shape' => 'ROUND',
            'pricing_mode' => 'per_carat',
            'status' => 'in_stock',
            'carat' => $carat,
            'price_per_carat' => '100',
            'pieces' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Stone::query()->where('name', $name)->firstOrFail();
    }

    /**
     * Register a jewellery piece through the real HTTP path.
     */
    private function registerJewellery(User $actor, string $name): JewelleryItem
    {
        $category = Category::query()->first() ?? Category::factory()->create();
        $metalId = (int) MetalType::query()->where('name', 'Gold')->value('id');
        $purityId = (int) Purity::query()->where('metal_type_id', $metalId)->where('name', '22K')->value('id');

        $this->actingAs($actor)->post(route('branch.jewellery-items.store'), [
            'category_id' => $category->id,
            'name' => $name,
            'metal_type_id' => $metalId,
            'purity_id' => $purityId,
            'gross_weight' => '5.000',
            'metal_weight' => '4.800',
            'status' => JewelleryItemStatus::AVAILABLE->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return JewelleryItem::query()->where('name', $name)->firstOrFail();
    }

    /**
     * Convert a jewellery piece to a completed invoice.
     */
    private function convertJewellery(User $actor, JewelleryItem $item): Invoice
    {
        $quotation = \App\Models\Quotation::query()->create([
            'branch_id' => $actor->branch_id,
            'quotation_number' => 'QTN-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'quotation_date' => now()->toDateString(),
            'status' => QuotationStatus::DRAFT,
            'walkin_name' => 'Walk-in',
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
            'purity_snapshot' => $this->purity()->name,
            'net_weight_snapshot' => $item->net_weight,
            'quantity' => 1,
            'gold_rate_applied' => 10000,
            'gold_value' => 4000,
            'making_charge_type' => 'none',
            'making_charges' => 0,
            'stone_value' => 0,
            'line_total' => 5000,
        ]);

        return app(InvoiceConversionService::class)->convert($quotation->fresh(['items']), [
            'payment_method' => 'cash',
        ], $actor);
    }

    /**
     * Seeded 22K gold purity.
     */
    private function purity(): Purity
    {
        return Purity::query()->where('name', '22K')->firstOrFail();
    }
}
