<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\PerformaStatus;
use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\Performa;
use App\Models\Purity;
use App\Models\Stone;
use App\Models\User;
use App\Services\StoneStockService;
use Database\Seeders\MetalPuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Performa credit issue, partial settlement, and unpaid return.
 */
class PerformaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->seed(MetalPuritySeeder::class);
    }

    /**
     * Branch admin with a live branch.
     */
    private function branchAdmin(): User
    {
        $branch = Branch::factory()->create();

        return User::factory()->admin($branch->id)->create();
    }

    /**
     * Seeded 22K purity.
     */
    private function purity(): Purity
    {
        return Purity::query()->where('name', '22K')->firstOrFail();
    }

    /**
     * Available jewellery piece in the actor's branch.
     */
    private function jewellery(User $admin): JewelleryItem
    {
        $purity = $this->purity();

        return JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'metal_type_id' => $purity->metal_type_id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);
    }

    /**
     * Loose stone packet with on-hand quantity.
     */
    private function stoneWithQty(User $admin, int $qty): Stone
    {
        $stone = Stone::factory()->create([
            'branch_id' => $admin->branch_id,
        ]);

        app(StoneStockService::class)->apply(
            $stone,
            StoneMovementType::INTAKE,
            $qty,
            $admin
        );

        return $stone->fresh();
    }

    /**
     * Active customer (customers are global, not branch-scoped).
     */
    private function customer(array $overrides = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'name' => 'Credit Buyer',
            'phone' => '03009876543',
        ], $overrides));
    }

    /**
     * Issue payload for one jewellery piece and optional stone qty.
     *
     * @return array<string, mixed>
     */
    private function issuePayload(JewelleryItem $item, ?Stone $stone = null, int $stoneQty = 1, ?Customer $customer = null): array
    {
        $lines = [[
            'line_type' => LineType::JEWELLERY_ITEM->value,
            'jewellery_item_id' => $item->id,
            'quantity' => 1,
        ]];

        if ($stone) {
            $lines[] = [
                'line_type' => LineType::LOOSE_STONE->value,
                'stone_id' => $stone->id,
                'quantity' => $stoneQty,
            ];
        }

        $customer ??= Customer::factory()->create();

        return [
            'customer_uuid' => $customer->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => $lines,
        ];
    }

    public function test_index_create_and_show_render(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)->get(route('branch.performas.index'))->assertOk();
        // New performas are made on the POS: the old address sends you there.
        $this->actingAs($admin)->get(route('branch.performas.create'))
            ->assertRedirect(route('branch.pos.show', ['kind' => 'performa']));

        $item = $this->jewellery($admin);
        $this->actingAs($admin)->postJson(route('branch.performas.store'), $this->issuePayload($item))
            ->assertOk()
            ->assertJsonPath('success', true);

        $performa = Performa::query()->firstOrFail();

        $this->actingAs($admin)->get(route('branch.performas.show', $performa))->assertOk();
        $this->actingAs($admin)->get(route('branch.performas.print', $performa))->assertOk();
        $this->actingAs($admin)->get(route('branch.performas.settle', $performa))
            ->assertOk()
            ->assertSee('net_weight_snapshot')
            ->assertSee('gold_rate_applied');
    }

    public function test_issue_deducts_jewellery_and_stones(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $stone = $this->stoneWithQty($admin, 100);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, $stone, 100))
            ->assertOk();

        $this->assertSame(JewelleryItemStatus::ON_MEMO, $item->fresh()->status);
        $this->assertSame(0, (int) $stone->fresh()->quantity);

        $performa = Performa::query()->firstOrFail();
        $this->assertSame(PerformaStatus::OPEN, $performa->status);
        // The performa belongs to the customer it was issued to.
        $this->assertNotNull($performa->customer_id);
        $this->assertSame(1, \App\Models\Customer::query()->whereKey($performa->customer_id)->count());

        $this->assertDatabaseHas('stone_stock_movements', [
            'stone_id' => $stone->id,
            'type' => StoneMovementType::ISSUED_ON_CREDIT->value,
            'quantity_change' => -100,
        ]);
    }

    public function test_issue_rejects_the_same_jewellery_piece_twice(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item))
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item))
            ->assertStatus(422);
    }

    public function test_issue_rejects_more_stones_than_on_hand(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $stone = $this->stoneWithQty($admin, 10);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, $stone, 11))
            ->assertStatus(422);

        $this->assertSame(JewelleryItemStatus::AVAILABLE, $item->fresh()->status);
        $this->assertSame(10, (int) $stone->fresh()->quantity);
    }

    public function test_partial_stone_settle_keeps_remaining_and_does_not_rededuct(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $stone = $this->stoneWithQty($admin, 100);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, $stone, 100))
            ->assertOk();

        $performa = Performa::query()->with('items')->firstOrFail();
        $stoneLine = $performa->items->firstWhere('line_type', LineType::LOOSE_STONE);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.settle.store', $performa), [
                'payment_method' => 'cash',
                'lines' => [[
                    'performa_item_id' => $stoneLine->id,
                    'quantity' => 50,
                    'price' => '10.00',
                ]],
            ])
            ->assertOk();

        $this->assertSame(0, (int) $stone->fresh()->quantity);
        $this->assertSame(JewelleryItemStatus::ON_MEMO, $item->fresh()->status);

        $stoneLine->refresh();
        $this->assertSame(50, $stoneLine->quantity_settled);
        $this->assertSame(50, $stoneLine->outstandingQuantity());
        $this->assertSame(PerformaStatus::PARTIAL, $performa->fresh()->status);

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame($performa->id, $invoice->performa_id);
        $this->assertSame(InvoiceStatus::COMPLETED, $invoice->status);
        $this->assertSame(50, (int) $invoice->items()->where('stone_id', $stone->id)->value('quantity'));

        $this->assertDatabaseHas('stone_stock_movements', [
            'stone_id' => $stone->id,
            'type' => StoneMovementType::SETTLED_FROM_CREDIT->value,
            'quantity_change' => 0,
        ]);
    }

    public function test_settle_one_jewellery_piece_leaves_other_lines_open(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $stone = $this->stoneWithQty($admin, 20);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, $stone, 20))
            ->assertOk();

        $performa = Performa::query()->with('items')->firstOrFail();
        $jewelleryLine = $performa->items->firstWhere('line_type', LineType::JEWELLERY_ITEM);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.settle.store', $performa), [
                'payment_method' => 'cash',
                'lines' => [[
                    'performa_item_id' => $jewelleryLine->id,
                    'quantity' => 1,
                    'price' => '15000.00',
                ]],
            ])
            ->assertOk();

        $this->assertSame(JewelleryItemStatus::SOLD, $item->fresh()->status);
        $this->assertSame(PerformaStatus::PARTIAL, $performa->fresh()->status);
        $this->assertSame(20, $performa->items()->where('stone_id', $stone->id)->first()->outstandingQuantity());
    }

    public function test_settle_remaining_closes_the_performa(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $stone = $this->stoneWithQty($admin, 5);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, $stone, 5))
            ->assertOk();

        $performa = Performa::query()->with('items')->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('branch.performas.settle.store', $performa), [
                'payment_method' => 'card',
                'lines' => $performa->items->map(fn ($line) => [
                    'performa_item_id' => $line->id,
                    'quantity' => $line->outstandingQuantity(),
                    'price' => '100.00',
                ])->all(),
            ])
            ->assertOk();

        $this->assertSame(PerformaStatus::SETTLED, $performa->fresh()->status);
        $this->assertSame(0, $performa->fresh()->outstandingSummary()['jewellery']);
        $this->assertSame(0, $performa->fresh()->outstandingSummary()['stones']);
    }

    public function test_cannot_settle_more_than_outstanding(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $stone = $this->stoneWithQty($admin, 10);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, $stone, 10))
            ->assertOk();

        $performa = Performa::query()->with('items')->firstOrFail();
        $stoneLine = $performa->items->firstWhere('line_type', LineType::LOOSE_STONE);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.settle.store', $performa), [
                'payment_method' => 'cash',
                'lines' => [[
                    'performa_item_id' => $stoneLine->id,
                    'quantity' => 11,
                    'price' => '10.00',
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_jewellery_settle_uses_gold_rate_and_making(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item))
            ->assertOk();

        $performa = Performa::query()->with('items')->firstOrFail();
        $jewelleryLine = $performa->items->firstWhere('line_type', LineType::JEWELLERY_ITEM);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.settle.store', $performa), [
                'payment_method' => 'cash',
                'lines' => [[
                    'performa_item_id' => $jewelleryLine->id,
                    'quantity' => 1,
                    'gold_rate_applied' => '1000.00',
                    'making_charge_type' => 'per_gram',
                    'making_charge_value' => '100.00',
                ]],
            ])
            ->assertOk();

        $invoiceItem = Invoice::query()->firstOrFail()->items()->firstOrFail();
        $this->assertSame('1000.00', (string) $invoiceItem->gold_rate_applied);
        $this->assertSame('5000.00', (string) $invoiceItem->gold_value);
        $this->assertSame('500.00', (string) $invoiceItem->making_charges);
        $this->assertSame('5500.00', (string) $invoiceItem->line_total);
    }

    public function test_return_unpaid_restores_stock(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $stone = $this->stoneWithQty($admin, 40);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, $stone, 40))
            ->assertOk();

        $performa = Performa::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('branch.performas.return', $performa), ['return_all' => 1])
            ->assertRedirect(route('branch.performas.show', $performa));

        $this->assertSame(JewelleryItemStatus::AVAILABLE, $item->fresh()->status);
        $this->assertSame(40, (int) $stone->fresh()->quantity);
        $this->assertSame(PerformaStatus::CANCELLED, $performa->fresh()->status);
    }

    public function test_return_selected_stone_qty_leaves_the_rest_outstanding(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $stone = $this->stoneWithQty($admin, 40);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, $stone, 40))
            ->assertOk();

        $performa = Performa::query()->with('items')->firstOrFail();
        $stoneLine = $performa->items->firstWhere('line_type', LineType::LOOSE_STONE);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.return', $performa), [
                'lines' => [[
                    'performa_item_id' => $stoneLine->id,
                    'quantity' => 10,
                ]],
            ])
            ->assertOk();

        $this->assertSame(JewelleryItemStatus::ON_MEMO, $item->fresh()->status);
        $this->assertSame(10, (int) $stone->fresh()->quantity);
        $this->assertSame(30, $stoneLine->fresh()->outstandingQuantity());
        $this->assertSame(PerformaStatus::OPEN, $performa->fresh()->status);
    }

    public function test_return_one_jewellery_piece_leaves_the_other_on_memo(): void
    {
        $admin = $this->branchAdmin();
        $first = $this->jewellery($admin);
        $second = $this->jewellery($admin);
        $customer = $this->customer();

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), [
                'customer_uuid' => $customer->uuid,
                'performa_date' => now()->toDateString(),
                'lines' => [
                    [
                        'line_type' => LineType::JEWELLERY_ITEM->value,
                        'jewellery_item_id' => $first->id,
                        'quantity' => 1,
                    ],
                    [
                        'line_type' => LineType::JEWELLERY_ITEM->value,
                        'jewellery_item_id' => $second->id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertOk();

        $performa = Performa::query()->with('items')->firstOrFail();
        $firstLine = $performa->items->firstWhere('jewellery_item_id', $first->id);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.return', $performa), [
                'lines' => [[
                    'performa_item_id' => $firstLine->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertOk();

        $this->assertSame(JewelleryItemStatus::AVAILABLE, $first->fresh()->status);
        $this->assertSame(JewelleryItemStatus::ON_MEMO, $second->fresh()->status);
        $this->assertSame(PerformaStatus::OPEN, $performa->fresh()->status);
    }

    public function test_customer_uuid_attaches_an_existing_customer(): void
    {
        $admin = $this->branchAdmin();
        $customer = $this->customer([
            'name' => 'Listed Customer',
            'phone' => '03001110000',
        ]);
        $item = $this->jewellery($admin);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, customer: $customer))
            ->assertOk();

        $this->assertSame($customer->id, Performa::query()->value('customer_id'));
    }

    public function test_walk_in_details_become_a_customer(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), [
                'walkin_name' => 'Credit Buyer',
                'walkin_phone' => '03009876543',
                'performa_date' => now()->toDateString(),
                'lines' => [[
                    'line_type' => LineType::JEWELLERY_ITEM->value,
                    'jewellery_item_id' => $item->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertOk();

        $customer = Customer::query()->where('phone', '03009876543')->sole();
        $this->assertSame($customer->id, Performa::query()->value('customer_id'));
    }

    public function test_issue_rejects_a_customer_in_the_trash(): void
    {
        $admin = $this->branchAdmin();
        $customer = $this->customer();
        $customer->delete();
        $item = $this->jewellery($admin);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, customer: $customer))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_uuid']);
        $this->assertSame(JewelleryItemStatus::AVAILABLE, $item->fresh()->status);
    }

    /**
     * A stone's STN code resolves on the issue workspace lookup.
     */
    public function test_lookup_accepts_stone_code(): void
    {
        $admin = $this->branchAdmin();
        $stone = $this->stoneWithQty($admin, 5);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.lookup'), ['code' => $stone->stone_code])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.line.stone_id', $stone->id);
    }

    /**
     * A unique jewellery name can be typed instead of the barcode.
     */
    public function test_lookup_accepts_unique_jewellery_name(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $item->forceFill(['name' => 'Celeste Halo Band'])->save();

        $this->actingAs($admin)
            ->postJson(route('branch.performas.lookup'), ['code' => 'Celeste Halo Band'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.line.jewellery_item_id', $item->id);
    }

    /**
     * Name search returns both available jewellery and in-stock stones.
     */
    public function test_search_finds_jewellery_and_stones_by_name(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);
        $item->forceFill(['name' => 'Halo solitaire'])->save();
        $stone = $this->stoneWithQty($admin, 3);
        $stone->forceFill(['name' => 'Halo melee'])->save();

        $response = $this->actingAs($admin)
            ->getJson(route('branch.performas.search', ['q' => 'halo']))
            ->assertOk()
            ->assertJsonPath('success', true);

        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $this->assertCount(2, $items);
        $this->assertContains($item->uuid, array_column($items, 'uuid'));
        $this->assertContains($stone->uuid, array_column($items, 'uuid'));
    }

    public function test_prices_are_kept_at_issue_and_always_printed(): void
    {
        $admin = $this->branchAdmin();
        $stone = $this->stoneWithQty($admin, 10);
        // Cost users start a stone line at the lot's own price per piece.
        $stone->forceFill(['pricing_mode' => 'per_piece', 'price_per_piece' => '7.5'])->save();
        $item = $this->jewellery($admin);
        $customer = $this->customer();

        $payload = $this->issuePayload($item, $stone, 4, $customer);

        $this->actingAs($admin)->postJson(route('branch.performas.store'), $payload)->assertOk();
        $priced = \App\Models\Performa::query()->latest('id')->firstOrFail();

        $stoneLine = $priced->items()->where('line_type', LineType::LOOSE_STONE->value)->firstOrFail();
        $this->assertSame('7.50', (string) $stoneLine->unit_price);
        $this->assertEqualsWithDelta(30.0, $stoneLine->valueOf(4), 0.001);

        $html = $this->actingAs($admin)->get(route('branch.performas.print', $priced))->assertOk()->getContent();
        $this->assertStringContainsString('>Price<', $html);
        $this->assertStringContainsString('Value issued', $html);
        $this->assertStringContainsString('7.50', $html);
    }

    public function test_every_printable_document_uses_the_one_sheet_design(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);

        $this->actingAs($admin)->postJson(route('branch.performas.store'), $this->issuePayload($item))->assertOk();
        $performa = \App\Models\Performa::query()->firstOrFail();

        $html = $this->actingAs($admin)->get(route('branch.performas.print', $performa))->assertOk()->getContent();

        // The shared design: accent bar, title, party block, facts, the table, the footer.
        foreach (['pd__bar', 'pd__title', 'pd__party', 'pd__facts', 'pd-table', 'pd__footer'] as $class) {
            $this->assertStringContainsString($class, $html, "performa print misses {$class}");
        }
        $this->assertStringContainsString($performa->performa_number, $html);
    }

    public function test_pos_issues_a_performa_to_a_new_person_who_is_saved_as_a_customer(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);

        $response = $this->actingAs($admin)->postJson(route('branch.performas.store'), [
            'walkin_name' => 'Lucia Ortega',
            'walkin_phone' => '03005551234',
            'walkin_email' => 'lucia@example.com',
            'performa_date' => now()->toDateString(),
            // The POS also sends these; a performa ignores them.
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'apply_tax' => true,
            'lines' => [[
                'line_type' => LineType::JEWELLERY_ITEM->value,
                'jewellery_item_id' => $item->id,
                'quantity' => 1,
            ]],
        ])->assertOk()->assertJsonPath('success', true);

        $performa = \App\Models\Performa::query()->latest('id')->firstOrFail();
        $customer = Customer::query()->where('phone', '03005551234')->firstOrFail();

        $this->assertSame($customer->id, $performa->customer_id);
        $this->assertSame('Lucia Ortega', $customer->name);
        $this->assertSame('lucia@example.com', $customer->email);
        $this->assertTrue((bool) $customer->status);
        $this->assertSame($performa->performa_number, $response->json('data.number'));
        $this->assertSame(route('branch.performas.print', $performa), $response->json('data.print_url'));
        $this->assertSame(JewelleryItemStatus::ON_MEMO, $item->fresh()->status);

        // The same phone is reused, not registered twice.
        $this->actingAs($admin)->postJson(route('branch.performas.store'), [
            'walkin_name' => 'Lucia O.',
            'walkin_phone' => '03005551234',
            'performa_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => LineType::JEWELLERY_ITEM->value,
                'jewellery_item_id' => $this->jewellery($admin)->id,
                'quantity' => 1,
            ]],
        ])->assertOk();
        $this->assertSame(1, Customer::query()->where('phone', '03005551234')->count());

        // A performa has no discount or tax columns at all.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('performas', 'discount_amount'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('performas', 'tax_amount'));
    }

    public function test_pos_needs_an_existing_customer_or_the_details_of_a_new_one(): void
    {
        $admin = $this->branchAdmin();
        $item = $this->jewellery($admin);

        $this->actingAs($admin)->postJson(route('branch.performas.store'), [
            'performa_date' => now()->toDateString(),
            'lines' => [['line_type' => LineType::JEWELLERY_ITEM->value, 'jewellery_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertSame(JewelleryItemStatus::AVAILABLE, $item->fresh()->status);
    }

    public function test_pos_shows_the_performa_kind_and_the_next_performa_number(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)->getJson(route('branch.pos.numbers'))
            ->assertOk()
            ->assertJson(fn ($json) => $json->has('data.performa')->etc());

        $this->actingAs($admin)->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('data-kind="performa"', false)
            ->assertSee('data-generate-performa', false);
    }

    public function test_new_performa_lands_on_the_pos_with_performa_chosen_and_the_customer_filled(): void
    {
        $admin = $this->branchAdmin();
        $customer = $this->customer(['name' => 'Alpha Gems', 'phone' => '03001110001']);

        $this->actingAs($admin)
            ->get(route('branch.performas.create', ['customer' => $customer->uuid]))
            ->assertRedirect(route('branch.pos.show', ['kind' => 'performa', 'customer' => $customer->uuid]));

        $page = $this->actingAs($admin)
            ->get(route('branch.pos.show', ['kind' => 'performa', 'customer' => $customer->uuid]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('&quot;defaultKind&quot;:&quot;performa&quot;', $page);
        $this->assertStringContainsString('Alpha Gems', $page);

        // Without ?kind= the POS opens on its usual default, and an unknown kind is ignored.
        $this->actingAs($admin)->get(route('branch.pos.show', ['kind' => 'nonsense']))
            ->assertOk()
            ->assertDontSee('&quot;defaultKind&quot;:&quot;performa&quot;', false);
    }

    public function test_discount_and_tax_are_kept_printed_and_carried_to_the_settlement_invoice(): void
    {
        $admin = $this->branchAdmin();
        $this->useTax(true, 10);
        app(\App\Services\SettingService::class)->setGroup(\App\Enums\SettingGroup::DISCOUNT, [
            'discount_enabled' => true,
            'max_discount_percentage' => 50,
        ], User::factory()->create());
        $stone = $this->stoneWithQty($admin, 10);
        $stone->forceFill(['pricing_mode' => 'per_piece', 'price_per_piece' => '10'])->save();
        $item = $this->jewellery($admin);
        $payload = $this->issuePayload($item, $stone, 10);
        $payload['lines'] = [$payload['lines'][1]];
        $ivaUuid = \App\Models\TaxRate::query()->where('kind', 'iva')->value('uuid');

        $this->actingAs($admin)->postJson(route('branch.performas.store'), $payload + [
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'apply_tax' => true,
            'tax_rate_uuid' => $ivaUuid,
        ])->assertOk();

        $performa = Performa::query()->with('items')->latest('id')->firstOrFail();
        $this->assertSame('percentage', $performa->discount_type->value);
        $this->assertSame('percentage', $performa->tax_type);
        // 100 issued, 20% off = 80, 10% IVA on 80 = 8, total 88.
        $totals = $performa->documentTotals();
        $this->assertEqualsWithDelta(100.0, (float) $totals['subtotal'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $totals['discount_amount'], 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $totals['tax_amount'], 0.001);
        $this->assertEqualsWithDelta(88.0, (float) $totals['grand_total'], 0.001);

        $html = $this->actingAs($admin)->get(route('branch.performas.print', $performa))->assertOk()->getContent();
        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('88.00', $html);

        // Settling half the goods carries the same discount and tax: 50 - 10 + 4 = 44.
        $line = $performa->items->firstOrFail();
        $this->actingAs($admin)->postJson(route('branch.performas.settle.store', $performa), [
            'payment_method' => 'cash',
            'tax_rate_uuid' => $ivaUuid,
            'lines' => [['performa_item_id' => $line->id, 'quantity' => 5, 'price' => '10.00', 'price_per_piece' => '10.00']],
        ])->assertOk();

        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(50.0, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $invoice->discount_amount, 0.001);
        $this->assertEqualsWithDelta(44.0, (float) $invoice->grand_total, 0.001);
    }

    public function test_a_discount_without_the_discount_setting_is_dropped(): void
    {
        $admin = $this->branchAdmin();
        app(\App\Services\SettingService::class)->setGroup(\App\Enums\SettingGroup::DISCOUNT, [
            'discount_enabled' => false,
        ], User::factory()->create());
        $stone = $this->stoneWithQty($admin, 2);
        $item = $this->jewellery($admin);
        $payload = $this->issuePayload($item, $stone, 2);

        $this->actingAs($admin)->postJson(route('branch.performas.store'), $payload + [
            'discount_type' => 'percentage',
            'discount_value' => 30,
        ])->assertOk();

        $this->assertSame('none', Performa::query()->latest('id')->firstOrFail()->discount_type->value);
    }
}
