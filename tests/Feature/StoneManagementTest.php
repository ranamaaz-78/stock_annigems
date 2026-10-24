<?php

namespace Tests\Feature;

use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use App\Models\Branch;
use App\Models\Stone;
use App\Models\StoneIntake;
use App\Models\StoneStockMovement;
use App\Models\User;
use App\Services\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Module 3: stone lot registration, pricing, barcodes, intake ledger,
 * drafts, and permissions.
 *
 * USB scanner hardware cannot run here. Manual checklist lives in
 * resources/js/modules/barcode-scanner.js.
 */
class StoneManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Shared registration payload for a per-piece lot.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'CITIRINO',
            'shape' => 'PERA',
            'dimension' => '8x6',
            'pricing_mode' => StonePricingMode::PER_PIECE->value,
            'status' => StoneStatus::IN_STOCK->value,
            'pieces' => 68,
            'pairs' => 0,
            'price_per_piece' => '9',
            'tray_no' => 'K1',
        ], $overrides);
    }

    /**
     * Sequential STN codes, with a separate random packet barcode.
     */
    public function test_registration_issues_sequential_codes_and_random_barcodes(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload([
            'name' => 'First lot',
        ]))->assertRedirect();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload([
            'name' => 'Second lot',
        ]))->assertRedirect();

        $stones = Stone::query()->orderBy('id')->get();

        $this->assertSame(['STN-000001', 'STN-000002'], $stones->pluck('stone_code')->all());

        foreach ($stones as $stone) {
            // The barcode is its own number, never a copy of the STN code.
            $this->assertNotSame($stone->stone_code, $stone->barcode);
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{5}$/', $stone->barcode);
        }

        $this->assertSame(2, $stones->pluck('barcode')->unique()->count());
    }

    /**
     * Per piece: pieces x rate, and the piece count becomes opening stock.
     */
    public function test_per_piece_lot_stores_total_and_opening_stock(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();

        $stone = Stone::query()->first();

        $this->assertSame('612.000', (string) $stone->total_cost);
        $this->assertSame('9.000', (string) $stone->piece_price);
        $this->assertSame('68 pcs x 9 = 612', $stone->cost_formula);
        $this->assertSame(68, (int) $stone->quantity);

        // Opening stock is explained by the ledger, not written silently.
        $movement = StoneStockMovement::query()->first();
        $this->assertSame(0, (int) $movement->quantity_before);
        $this->assertSame(68, (int) $movement->quantity_after);
    }

    /**
     * Per carat is carats x rate; the carats are the stock and pieces play no part.
     */
    public function test_per_carat_lot_costs_carats_and_holds_no_pieces(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload([
            'name' => 'AMATISTA',
            'pricing_mode' => StonePricingMode::PER_CARAT->value,
            'carat' => '2.100',
            'price_per_carat' => '2',
            'pieces' => 2,
            'price_per_piece' => null,
        ]))->assertRedirect();

        $stone = Stone::query()->first();

        $this->assertNull($stone->piece_price);
        $this->assertSame('4.200', (string) $stone->total_cost);
        $this->assertSame('2.1 ct x 2 = 4.2', $stone->cost_formula);
        // The carats are the stock, kept as a measure; no pieces are counted.
        $this->assertTrue($stone->isWeighed());
        $this->assertSame('2.100', (string) $stone->weight_grams);
        $this->assertSame(0, (int) $stone->quantity);
        // A carat lot holds no piece rate of its own.
        $this->assertNull($stone->price_per_piece);
    }

    /**
     * Per gram is bulk weight: no pieces, no piece price.
     */
    public function test_per_gram_lot_costs_weight_and_holds_no_pieces(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload([
            'name' => 'LABRADORITA',
            'pricing_mode' => StonePricingMode::PER_GRAM->value,
            'weight_grams' => '150',
            'price_per_gram' => '2.5',
            'pieces' => null,
            'price_per_piece' => null,
        ]))->assertRedirect();

        $stone = Stone::query()->first();

        $this->assertSame('375.000', (string) $stone->total_cost);
        $this->assertNull($stone->piece_price);
        $this->assertNull($stone->carat);
        $this->assertSame(0, (int) $stone->quantity);
    }

    /**
     * Each mode demands the figures its own formula consumes.
     */
    public function test_pricing_mode_requires_its_own_figures(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)
            ->from(route('branch.stones.create'))
            ->post(route('branch.stones.store'), $this->registrationPayload([
                'pricing_mode' => StonePricingMode::PER_CARAT->value,
                'price_per_piece' => null,
            ]))
            ->assertSessionHasErrors(['carat', 'price_per_carat']);

        $this->assertSame(0, Stone::query()->count());
    }

    /**
     * Barcode is printable and shown on the detail page.
     */
    public function test_barcode_renders_png_on_the_detail_page(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();

        $stone = Stone::query()->first();
        $uri = app(BarcodeService::class)->dataUri($stone->barcode);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        $this->actingAs($admin)
            ->get(route('branch.stones.show', $stone))
            ->assertOk()
            ->assertSee('data:image/png;base64,', false)
            ->assertSee('68 pcs x 9 = 612', false);
    }

    /**
     * Other-branch uuid is 403; unknown uuid is 404.
     */
    public function test_cross_branch_stone_is_forbidden_missing_is_not_found(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $adminA = User::factory()->admin($branchA->id)->create();
        $adminB = User::factory()->admin($branchB->id)->create();

        $this->actingAs($adminB)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();
        $foreign = Stone::query()->withoutGlobalScopes()->first();

        $this->actingAs($adminA)
            ->get(route('branch.stones.show', $foreign))
            ->assertForbidden();

        $this->actingAs($adminA)
            ->get('/branch/stones/00000000-0000-4000-8000-000000000000')
            ->assertNotFound();
    }

    /**
     * Scan lookup copy distinguishes unknown from another branch.
     */
    public function test_scan_lookup_distinguishes_missing_from_other_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $adminA = User::factory()->admin($branchA->id)->create();
        $adminB = User::factory()->admin($branchB->id)->create();

        $this->actingAs($adminB)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();
        $foreign = Stone::query()->withoutGlobalScopes()->first();

        $this->actingAs($adminA)
            ->postJson(route('branch.stone-intakes.lookup'), ['code' => $foreign->barcode])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found in this branch.');

        $this->actingAs($adminA)
            ->postJson(route('branch.stone-intakes.lookup'), ['code' => 'NO-SUCH-CODE'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Barcode not found.');
    }

    /**
     * Cashier has no stone permissions.
     */
    public function test_cashier_cannot_view_stones_or_intake(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier($branch->id)->create();

        $this->actingAs($cashier)->get(route('branch.stones.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('branch.stone-intakes.scan'))->assertForbidden();
        $this->actingAs($cashier)->post(route('branch.stones.store'), $this->registrationPayload())->assertForbidden();
    }

    /**
     * Intake adds to the piece count and writes a self-checking ledger row.
     */
    public function test_intake_updates_quantity_and_writes_ledger(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload([
            'pieces' => 1,
        ]))->assertRedirect();
        $stone = Stone::query()->first();
        $this->assertSame(1, (int) $stone->quantity);

        $this->actingAs($admin)
            ->postJson(route('branch.stone-intakes.store'), [
                'intake_date' => now()->toDateString(),
                'notes' => 'Morning parcel',
                'rows' => [
                    [
                        'uuid' => $stone->uuid,
                        'quantity' => 4,
                        'scanned_count' => 4,
                    ],
                ],
            ])
            ->assertCreated();

        $stone->refresh();
        $this->assertSame(5, (int) $stone->quantity);

        $movement = StoneStockMovement::query()->latest('id')->first();
        $this->assertSame(1, (int) $movement->quantity_before);
        $this->assertSame(5, (int) $movement->quantity_after);
        $this->assertSame(4, (int) $movement->quantity_change);
        $this->assertSame(1, StoneIntake::query()->count());
        $this->assertSame('SIN-000001', StoneIntake::query()->value('intake_number'));
    }

    /**
     * One field finds a lot by barcode, STN code or name, gram lots included.
     */
    public function test_intake_finds_by_barcode_code_and_name(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $piece = Stone::factory()->for($branch)->create(['name' => 'CITIRINO', 'barcode' => '1234567890']);
        $gram = Stone::factory()->perGram()->for($branch)->create(['name' => 'CITIRINO BULK', 'barcode' => '9876543210']);

        foreach ([$piece->barcode, $piece->stone_code] as $code) {
            $this->actingAs($admin)
                ->postJson(route('branch.stone-intakes.lookup'), ['code' => $code])
                ->assertOk()
                ->assertJsonPath('data.stone.uuid', $piece->uuid);
        }

        $this->actingAs($admin)
            ->postJson(route('branch.stone-intakes.lookup'), ['code' => $gram->barcode])
            ->assertOk()
            ->assertJsonPath('data.stone.pricing_mode', 'per_gram');

        $found = $this->actingAs($admin)
            ->getJson(route('branch.stones.search', ['q' => 'CITIRINO']))
            ->assertOk()
            ->json('data.stones');
        $this->assertEqualsCanonicalizing([$piece->uuid, $gram->uuid], array_column($found, 'uuid'));
    }

    /**
     * Receiving pieces grows the lot's cost at the lot's own rate.
     */
    public function test_intake_pieces_add_to_the_lots_cost(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        // 68 pcs at 9 = 612.
        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();
        $stone = Stone::query()->firstOrFail();
        $this->assertSame('612.000', (string) $stone->total_cost);

        $this->actingAs($admin)->postJson(route('branch.stone-intakes.store'), [
            'intake_date' => now()->toDateString(),
            'rows' => [['uuid' => $stone->uuid, 'quantity' => 10]],
        ])->assertCreated();

        $stone->refresh();
        $this->assertSame(78, (int) $stone->quantity);
        $this->assertSame('702.000', (string) $stone->total_cost);
        $this->assertSame('612 + 10 pcs x 9 = 702', $stone->cost_formula);
        // The rate itself is never rewritten.
        $this->assertSame('9.000', (string) $stone->price_per_piece);
    }

    /**
     * A per-gram lot is received in grams: weight, ledger, cost and the
     * intake line all carry the grams, and pieces stay at zero.
     */
    public function test_intake_receives_grams_for_a_gram_lot(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        // 150 g at 2.5 = 375.
        $gram = Stone::factory()->perGram()->for($branch)->create(['total_cost' => '375.000']);

        $this->actingAs($admin)->postJson(route('branch.stone-intakes.store'), [
            'intake_date' => now()->toDateString(),
            'rows' => [['uuid' => $gram->uuid, 'weight_grams' => '40.5']],
        ])->assertCreated();

        $gram->refresh();
        $this->assertSame('190.500', (string) $gram->weight_grams);
        $this->assertSame(0, (int) $gram->quantity);
        // 40.5 g at 2.5 = 101.25 on top of 375.
        $this->assertSame('476.250', (string) $gram->total_cost);

        $movement = StoneStockMovement::query()->where('stone_id', $gram->id)->latest('id')->firstOrFail();
        $this->assertSame('150.000', (string) $movement->weight_before);
        $this->assertSame('190.500', (string) $movement->weight_after);
        $this->assertSame('40.500', (string) $movement->weight_change);
        $this->assertSame(0, (int) $movement->quantity_change);

        $intake = StoneIntake::query()->firstOrFail();
        $this->assertSame('40.500', (string) $intake->total_weight_grams);
        $this->assertSame(0, (int) $intake->total_quantity);
        $this->assertSame('40.500', (string) $intake->items->first()->weight_grams);
    }

    /**
     * Each lot must be counted the way it is bought: pieces sent for a gram
     * lot, with no grams, are refused and nothing is saved.
     */
    public function test_intake_requires_the_unit_the_lot_is_counted_in(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $gram = Stone::factory()->perGram()->for($branch)->create();

        $this->actingAs($admin)->postJson(route('branch.stone-intakes.store'), [
            'intake_date' => now()->toDateString(),
            'rows' => [['uuid' => $gram->uuid, 'quantity' => 5]],
        ])->assertStatus(422);

        $this->assertSame(0, StoneIntake::query()->count());
        $this->assertSame('150.000', (string) $gram->fresh()->weight_grams);
    }

    /**
     * Registering or editing a gram lot moves its weight through the ledger.
     */
    public function test_gram_lot_weight_goes_through_the_ledger(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $gramPayload = fn (string $weight): array => $this->registrationPayload([
            'name' => 'LABRADORITA',
            'pricing_mode' => StonePricingMode::PER_GRAM->value,
            'weight_grams' => $weight,
            'price_per_gram' => '2',
        ]);

        $this->actingAs($admin)->post(route('branch.stones.store'), $gramPayload('100'))->assertRedirect();

        $stone = Stone::query()->firstOrFail();
        $this->assertSame('100.000', (string) $stone->weight_grams);
        $opening = StoneStockMovement::query()->where('stone_id', $stone->id)->firstOrFail();
        $this->assertSame('intake', $opening->type->value);
        $this->assertSame('0.000', (string) $opening->weight_before);
        $this->assertSame('100.000', (string) $opening->weight_after);

        $this->actingAs($admin)
            ->put(route('branch.stones.update', $stone), array_merge($gramPayload('90'), ['is_active' => '1']))
            ->assertRedirect();

        $this->assertSame('90.000', (string) $stone->fresh()->weight_grams);
        $adjust = StoneStockMovement::query()->where('stone_id', $stone->id)->latest('id')->firstOrFail();
        $this->assertSame('adjusted', $adjust->type->value);
        $this->assertSame('-10.000', (string) $adjust->weight_change);
    }

    /**
     * The scan page has one find field and no separate short-code box.
     */
    public function test_intake_page_has_a_single_find_field(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($admin)
            ->get(route('branch.stone-intakes.scan'))
            ->assertOk()
            ->assertSee('data-scan-input', false)
            ->assertSee('data-suggest', false)
            ->assertDontSee('data-short-code-input', false)
            ->assertDontSee('data-add-by-name', false);
    }

    /**
     * Direct quantity assignment is blocked.
     */
    public function test_quantity_cannot_be_written_outside_stock_service(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();

        $stone = Stone::query()->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StoneStockService');
        $stone->quantity = 9;
    }

    /**
     * Lookup rate limit is per user.
     */
    public function test_scan_lookup_is_throttled(): void
    {
        config(['annigems.stones.lookup_throttle_per_minute' => 2]);

        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)
            ->postJson(route('branch.stone-intakes.lookup'), ['code' => 'NOPE-1'])
            ->assertNotFound();
        $this->actingAs($admin)
            ->postJson(route('branch.stone-intakes.lookup'), ['code' => 'NOPE-2'])
            ->assertNotFound();
        $this->actingAs($admin)
            ->postJson(route('branch.stone-intakes.lookup'), ['code' => 'NOPE-3'])
            ->assertStatus(429);
    }

    /**
     * Saved intakes have no update route.
     */
    public function test_intakes_cannot_be_updated(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('branch.stone-intakes.update'));

        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();
        $stone = Stone::query()->first();

        $this->actingAs($admin)
            ->postJson(route('branch.stone-intakes.store'), [
                'intake_date' => now()->toDateString(),
                'rows' => [['uuid' => $stone->uuid, 'quantity' => 1, 'scanned_count' => 1]],
            ])
            ->assertCreated();

        $intake = StoneIntake::query()->first();

        $this->actingAs($admin)
            ->putJson('/branch/stone-intakes/'.$intake->uuid, ['notes' => 'rewrite'])
            ->assertStatus(405);
    }

    /**
     * Posted codes and totals are ignored — the server issues its own.
     */
    public function test_registration_ignores_client_supplied_codes_and_totals(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)
            ->post(route('branch.stones.store'), $this->registrationPayload([
                'stone_code' => 'HACK-1',
                'barcode' => 'HACK-1',
                'total_cost' => '1',
                'quantity' => 99,
            ]))
            ->assertRedirect();

        $stone = Stone::query()->first();
        $this->assertSame('STN-000001', $stone->stone_code);
        $this->assertNotSame('HACK-1', $stone->barcode);
        $this->assertSame('612.000', (string) $stone->total_cost);
        $this->assertSame(68, (int) $stone->quantity);
    }

    /**
     * Manager cannot deactivate; inventory staff can still run intake.
     */
    public function test_manager_and_inventory_staff_permission_edges(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->manager($branch->id)->create();
        $staff = User::factory()->inventoryStaff($branch->id)->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();
        $stone = Stone::query()->first();

        $this->actingAs($manager)
            ->post(route('branch.stones.deactivate', $stone))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('branch.stone-intakes.scan'))
            ->assertOk();
    }

    /**
     * Correcting the piece count on edit is recorded as a movement.
     */
    public function test_editing_the_piece_count_writes_an_adjustment(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();
        $stone = Stone::query()->first();

        $this->actingAs($admin)
            ->put(route('branch.stones.update', $stone), $this->registrationPayload([
                'pieces' => 60,
                'is_active' => 1,
            ]))
            ->assertRedirect();

        $stone->refresh();
        $this->assertSame(60, (int) $stone->quantity);
        $this->assertSame('540.000', (string) $stone->total_cost);

        $movement = StoneStockMovement::query()->latest('id')->first();
        $this->assertSame(-8, (int) $movement->quantity_change);
        $this->assertSame(68, (int) $movement->quantity_before);
        $this->assertSame(60, (int) $movement->quantity_after);
    }

    /**
     * Changing the price keeps the one it replaced as the last price.
     * Saving without changing it, or editing other fields, leaves it alone.
     */
    public function test_changing_the_price_saves_the_previous_one_as_last_price(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        // Registered at 9 per piece: nothing to remember yet.
        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();
        $stone = Stone::query()->firstOrFail();
        $this->assertNull($stone->last_rate);
        $this->assertSame('—', $stone->lastRateLabel());

        $edit = fn (array $overrides) => $this->actingAs($admin)
            ->put(route('branch.stones.update', $stone), $this->registrationPayload(array_merge(['is_active' => 1], $overrides)))
            ->assertRedirect();

        // Same price, other field edited: still no last price.
        $edit(['tray_no' => 'K9']);
        $this->assertNull($stone->fresh()->last_rate);

        // 9 -> 12: the old 9 becomes the last price.
        $edit(['price_per_piece' => '12']);
        $stone->refresh();
        $this->assertSame('12.000', (string) $stone->price_per_piece);
        $this->assertSame('9.000', (string) $stone->last_rate);
        $this->assertSame('per_piece', $stone->last_rate_mode->value);
        $this->assertNotNull($stone->rate_changed_at);
        $this->assertStringEndsWith('/ pc', $stone->lastRateLabel());

        // Saving 12 again does not overwrite the last price with 12.
        $edit(['price_per_piece' => '12']);
        $this->assertSame('9.000', (string) $stone->fresh()->last_rate);

        // 12 -> 15: now 12 is the last price.
        $edit(['price_per_piece' => '15']);
        $this->assertSame('12.000', (string) $stone->fresh()->last_rate);

        // The list and the detail page show it.
        // In the list it sits under the rate as "was <last rate>".
        $this->actingAs($admin)->get(route('branch.stones.index', ['pricing_mode' => 'per_piece']))
            ->assertOk()->assertSee('was '.$stone->fresh()->lastRateLabel());
        $this->actingAs($admin)->get(route('branch.stones.show', $stone))
            ->assertOk()->assertSee('Last rate');
        $this->actingAs($admin)->get(route('branch.stones.edit', $stone))
            ->assertOk()->assertSee('Last price');
    }

    /**
     * Moving a lot to another pricing mode still remembers the old price in
     * the unit it was set in, and a client-sent last price is ignored.
     */
    public function test_last_price_keeps_its_own_unit_and_cannot_be_posted(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->registrationPayload())->assertRedirect();
        $stone = Stone::query()->firstOrFail();

        $this->actingAs($admin)
            ->put(route('branch.stones.update', $stone), $this->registrationPayload([
                'pricing_mode' => StonePricingMode::PER_GRAM->value,
                'weight_grams' => '100',
                'price_per_gram' => '2',
                'is_active' => 1,
                'last_rate' => '999',
            ]))
            ->assertRedirect();

        $stone->refresh();
        $this->assertSame('9.000', (string) $stone->last_rate);
        $this->assertSame('per_piece', $stone->last_rate_mode->value);
        $this->assertStringEndsWith('/ pc', $stone->lastRateLabel());
        $this->assertStringEndsWith('/ g', $stone->rateLabel());
    }

    /**
     * Stone figures keep at most three decimals: the form refuses more, and
     * a figure that arrives with more (an import sheet) is rounded before the
     * total is worked out, so the saved rate and the saved total agree.
     */
    public function test_stone_figures_are_limited_to_three_decimals(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        // The form refuses a fourth decimal on a rate and on grams.
        $this->actingAs($admin)
            ->postJson(route('branch.stones.store'), $this->registrationPayload(['price_per_piece' => '9.1234']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price_per_piece']);

        $this->actingAs($admin)
            ->postJson(route('branch.stones.store'), $this->registrationPayload([
                'pricing_mode' => StonePricingMode::PER_GRAM->value,
                'weight_grams' => '10.0001',
                'price_per_gram' => '2',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weight_grams']);

        // Three decimals are accepted and kept exactly.
        $this->actingAs($admin)
            ->post(route('branch.stones.store'), $this->registrationPayload(['price_per_piece' => '9.125', 'pieces' => 8]))
            ->assertRedirect();
        $stone = Stone::query()->firstOrFail();
        $this->assertSame('9.125', (string) $stone->price_per_piece);
        $this->assertSame('73.000', (string) $stone->total_cost);

        // The pricing service rounds a longer figure to three places before it multiplies.
        $priced = app(\App\Services\StonePricingService::class)->calculate([
            'pricing_mode' => StonePricingMode::PER_PIECE,
            'pieces' => 3,
            'price_per_piece' => '2.12345',
        ]);
        $this->assertSame(6.369, $priced['total_cost']); // 3 x 2.123, not 3 x 2.12345
        $this->assertSame('3 pcs x 2.123 = 6.369', $priced['cost_formula']);
    }

    /**
     * The print dialog gets each stone's label data with a vector barcode;
     * stones without a barcode are counted, not listed; a cashier and another
     * branch get nothing.
     */
    public function test_label_dialog_data_returns_vector_barcodes(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $with = Stone::factory()->for($branch)->create(['name' => 'CITIRINO', 'barcode' => '1234567890']);
        $without = Stone::factory()->for($branch)->create(['barcode' => null]);

        $response = $this->actingAs($admin)
            ->postJson(route('branch.stones.labels.data'), ['uuids' => [$with->uuid, $without->uuid]])
            ->assertOk()
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonCount(1, 'data.stones')
            ->assertJsonPath('data.stones.0.uuid', $with->uuid)
            ->assertJsonPath('data.stones.0.name', 'CITIRINO')
            ->assertJsonPath('data.stones.0.barcode', '1234567890')
            ->assertJsonPath('data.stones.0.stone_code', $with->stone_code);

        $svg = $response->json('data.stones.0.barcode_svg');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('preserveAspectRatio="none"', $svg);
        $this->assertStringContainsString('<rect', $svg);
        // Sized by the label, not fixed pixels, and no hidden repeat of the number.
        $this->assertStringNotContainsString('width="180"', $svg);
        $this->assertStringNotContainsString('<desc>', $svg);

        // A cashier cannot print labels.
        $cashier = User::factory()->cashier($branch->id)->create();
        $this->actingAs($cashier)
            ->postJson(route('branch.stones.labels.data'), ['uuids' => [$with->uuid]])
            ->assertForbidden();

        // Another branch's stone is never returned.
        $other = User::factory()->admin(Branch::factory()->create()->id)->create();
        $this->actingAs($other)
            ->postJson(route('branch.stones.labels.data'), ['uuids' => [$with->uuid]])
            ->assertOk()
            ->assertJsonCount(0, 'data.stones');

        // Nothing chosen is refused.
        $this->actingAs($admin)
            ->postJson(route('branch.stones.labels.data'), ['uuids' => []])
            ->assertStatus(422);
    }

    /**
     * The list and the detail page carry the print dialog and its openers.
     */
    public function test_pages_carry_the_label_dialog(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $stone = Stone::factory()->for($branch)->create(['barcode' => '1234567890']);

        $show = $this->actingAs($admin)->get(route('branch.stones.show', $stone))->assertOk();
        $show->assertSee('id="label-modal"', false)->assertSee('data-label-open="'.$stone->uuid.'"', false);
        $show->assertSee(route('branch.stones.labels.data'), false);

        $index = $this->actingAs($admin)->get(route('branch.stones.index', ['pricing_mode' => 'per_piece']))->assertOk();
        $index->assertSee('id="label-modal"', false)->assertSee('data-label-selected', false);
        $index->assertSee('Copies per product', false)->assertDontSee('Show sr number', false);

        // Cashiers do not get the dialog.
        $cashier = User::factory()->cashier($branch->id)->create();
        $this->actingAs($cashier)->get(route('branch.stones.index'))->assertForbidden();
    }
}
