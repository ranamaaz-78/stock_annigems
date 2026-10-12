<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\PerformaStatus;
use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Creditor;
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
     * Active creditor on the actor's branch.
     */
    private function creditor(User $admin, array $overrides = []): Creditor
    {
        return Creditor::factory()->create(array_merge([
            'branch_id' => $admin->branch_id,
            'name' => 'Credit Buyer',
            'phone' => '03009876543',
        ], $overrides));
    }

    /**
     * Issue payload for one jewellery piece and optional stone qty.
     *
     * @return array<string, mixed>
     */
    private function issuePayload(JewelleryItem $item, ?Stone $stone = null, int $stoneQty = 1, ?Creditor $creditor = null): array
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

        $creditor ??= Creditor::factory()->create([
            'branch_id' => $item->branch_id,
        ]);

        return [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => $lines,
        ];
    }

    public function test_index_create_and_show_render(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)->get(route('branch.performas.index'))->assertOk();
        $this->actingAs($admin)->get(route('branch.performas.create'))
            ->assertOk()
            ->assertSee('Issue performa')
            ->assertSee('Short code')
            ->assertSee('Search by name');

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
        $this->assertNotNull($performa->creditor_id);
        $this->assertSame(0, \App\Models\Customer::query()->count());

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
        $creditor = $this->creditor($admin);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), [
                'creditor_uuid' => $creditor->uuid,
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

    public function test_creditor_uuid_attaches_an_existing_creditor(): void
    {
        $admin = $this->branchAdmin();
        $creditor = $this->creditor($admin, [
            'name' => 'Listed Creditor',
            'phone' => '03001110000',
        ]);
        $item = $this->jewellery($admin);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, creditor: $creditor))
            ->assertOk();

        $this->assertSame($creditor->id, Performa::query()->value('creditor_id'));
    }

    public function test_issue_rejects_walk_in_customer_fields(): void
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
            ->assertStatus(422)
            ->assertJsonValidationErrors(['creditor_uuid']);
    }

    public function test_issue_rejects_an_inactive_creditor(): void
    {
        $admin = $this->branchAdmin();
        $creditor = $this->creditor($admin, ['status' => false]);
        $item = $this->jewellery($admin);

        $this->actingAs($admin)
            ->postJson(route('branch.performas.store'), $this->issuePayload($item, creditor: $creditor))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['creditor_uuid']);
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
}
