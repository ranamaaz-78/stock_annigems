<?php

namespace Tests\Feature;

use App\Enums\JewelleryItemStatus;
use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\JewelleryItem;
use App\Models\JewelleryItemStone;
use App\Models\MetalType;
use App\Models\Purity;
use App\Models\Stone;
use App\Models\StoneStockMovement;
use App\Models\User;
use App\Services\StoneStockService;
use Database\Seeders\MetalPuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Module 4: jewellery pieces, stone allocation deltas, catalogs, public page.
 */
class JewelleryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->seed(MetalPuritySeeder::class);
        RateLimiter::clear('public-item:127.0.0.1');
    }

    /**
     * @return array{0: Branch, 1: User, 2: Category, 3: int, 4: int}
     */
    private function branchContext(): array
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create(['name' => 'Branch Admin Ali']);
        $category = Category::factory()->create(['name' => 'Rings']);
        $metalId = (int) MetalType::query()->where('name', 'Gold')->value('id');
        $purityId = (int) Purity::query()->where('metal_type_id', $metalId)->where('name', '22K')->value('id');

        return [$branch, $admin, $category, $metalId, $purityId];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemPayload(Category $category, int $metalId, int $purityId, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'name' => 'Solitaire ring',
            'metal_type_id' => $metalId,
            'purity_id' => $purityId,
            'gross_weight' => '10.000',
            'metal_weight' => '9.500',
            'status' => JewelleryItemStatus::AVAILABLE->value,
            'description' => 'Internal bench notes — never public.',
        ], $overrides);
    }

    /**
     * Register a stone in the branch and credit on-hand quantity.
     */
    private function stockedStone(User $admin, Branch $branch, int $quantity = 5, string $carat = '0.200'): Stone
    {
        $this->actingAs($admin)->post(route('branch.stones.store'), [
            'name' => 'Round brilliant '.$carat.'ct',
            'shape' => 'ROUND',
            'pricing_mode' => 'per_carat',
            'status' => 'in_stock',
            'carat' => $carat,
            'price_per_carat' => '100',
            'pieces' => 1,
        ])->assertRedirect();

        $stone = Stone::query()->first();
        $this->assertNotNull($stone);

        // Registration already opens stock at one piece, so only the
        // difference is applied to land on exactly the requested quantity.
        $delta = $quantity - (int) $stone->quantity;

        if ($delta !== 0) {
            app(StoneStockService::class)->apply(
                $stone,
                $delta > 0 ? StoneMovementType::INTAKE : StoneMovementType::ADJUSTED,
                $delta,
                $admin
            );
        }

        return $stone->fresh();
    }

    /**
     * Sequential JWL codes; barcode equals code; qr_token is not the uuid.
     */
    public function test_create_issues_sequential_codes_and_separate_qr_token(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();

        $this->actingAs($admin)
            ->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, ['name' => 'First']))
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, ['name' => 'Second']))
            ->assertRedirect();

        $items = JewelleryItem::query()->orderBy('id')->get();
        $this->assertSame(['JWL-000001', 'JWL-000002'], $items->pluck('item_code')->all());
        $this->assertSame($items->pluck('item_code')->all(), $items->pluck('barcode')->all());
        $this->assertNotSame($items[0]->uuid, $items[0]->qr_token);
        $this->assertSame(48, strlen($items[0]->qr_token));
    }

    /**
     * Barcode, QR, and both print as separate jobs. Each unique piece appears once;
     * selected pieces share a single A4 grid.
     */
    public function test_jewellery_label_sheets_separate_barcode_and_qr(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();

        $this->actingAs($admin)
            ->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
                'name' => 'Classic hoop',
            ]))
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
                'name' => 'Plain band',
            ]))
            ->assertRedirect();

        $items = JewelleryItem::query()->orderBy('id')->get();
        $first = $items[0];
        $second = $items[1];

        $barcodeHtml = $this->actingAs($admin)
            ->get(route('branch.jewellery-items.label', [
                'jewelleryItem' => $first,
                'kind' => 'barcode',
            ]))
            ->assertOk()
            ->assertSee('Classic hoop', false)
            ->assertSee($first->item_code, false)
            ->assertDontSee('Gross', false)
            ->getContent();
        $this->assertSame(1, substr_count($barcodeHtml, 'stone-label-cell '));
        $this->assertStringNotContainsString('stone-label-cell--qr', $barcodeHtml);

        $qrHtml = $this->actingAs($admin)
            ->get(route('branch.jewellery-items.label', [
                'jewelleryItem' => $first,
                'kind' => 'qr',
            ]))
            ->assertOk()
            ->getContent();
        $this->assertSame(1, substr_count($qrHtml, 'stone-label-cell--qr'));

        $bothHtml = $this->actingAs($admin)
            ->get(route('branch.jewellery-items.label', [
                'jewelleryItem' => $first,
                'kind' => 'both',
            ]))
            ->assertOk()
            ->assertSee('Classic hoop', false)
            ->getContent();
        $this->assertSame(1, substr_count($bothHtml, 'stone-label-cell--both'));
        $this->assertStringContainsString('stone-label-cell__barcode', $bothHtml);
        $this->assertStringContainsString('stone-label-cell__qr', $bothHtml);

        $bulkHtml = $this->actingAs($admin)
            ->get(route('branch.jewellery-items.labels.bulk', [
                'kind' => 'barcode',
                'uuids' => [$first->uuid, $second->uuid],
            ]))
            ->assertOk()
            ->assertSee('Classic hoop', false)
            ->assertSee('Plain band', false)
            ->getContent();
        $this->assertSame(2, substr_count($bulkHtml, 'stone-label-cell '));
        $this->assertSame(1, substr_count($bulkHtml, 'stone-label-page'));

        $this->actingAs($admin)
            ->get('/branch/jewellery-items/'.$first->uuid.'/label')
            ->assertRedirect(route('branch.jewellery-items.label', [$first, 'barcode']));
    }

    /**
     * Pieces have no quantity column; jewellery migrations have no price columns.
     */
    public function test_schema_has_no_quantity_or_price_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('jewellery_items', 'quantity'));
        $this->assertFalse(Schema::hasColumn('jewellery_items', 'purchase_cost'));
        $this->assertFalse(Schema::hasColumn('jewellery_items', 'sale_price'));
        $this->assertFalse(Schema::hasColumn('jewellery_items', 'making_charges'));

        foreach (File::glob(database_path('migrations/*jewellery*')) as $path) {
            $sql = File::get($path);
            $this->assertDoesNotMatchRegularExpression("/\\\$table->\\w+\\('purchase_cost'/", $sql);
            $this->assertDoesNotMatchRegularExpression("/\\\$table->\\w+\\('sale_price'/", $sql);
            $this->assertDoesNotMatchRegularExpression("/\\\$table->\\w+\\('making_charges'/", $sql);
        }
    }

    /**
     * Creating with stones deducts stock and writes used_in_item against the item.
     */
    public function test_create_with_stones_deducts_stock(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 5);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [
                ['uuid' => $stone->uuid, 'quantity' => 2],
            ],
        ]))->assertRedirect();

        $this->assertSame(3, (int) $stone->fresh()->quantity);
        $item = JewelleryItem::query()->first();
        $this->assertSame('0.400', (string) $item->stone_weight_carat);
        $this->assertSame('0.080', (string) $item->stone_weight_grams);
        $this->assertSame('9.500', (string) $item->net_weight);

        $used = StoneStockMovement::query()->where('type', StoneMovementType::USED_IN_ITEM)->first();
        $this->assertNotNull($used);
        $this->assertSame(-2, (int) $used->quantity_change);
        $this->assertSame(JewelleryItem::class, $used->reference_type);
        $this->assertSame($item->id, $used->reference_id);
    }

    /**
     * Unchanged allocation writes no extra movements; +qty deducts; −qty returns.
     */
    public function test_edit_applies_allocation_delta_only(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 10);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 2]],
        ]))->assertRedirect();

        $item = JewelleryItem::query()->first();
        $usedCount = StoneStockMovement::query()->where('type', StoneMovementType::USED_IN_ITEM)->count();

        $this->actingAs($admin)->put(route('branch.jewellery-items.update', $item), $this->itemPayload($category, $metalId, $purityId, [
            'name' => 'Solitaire ring',
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 2]],
        ]))->assertRedirect();

        $this->assertSame($usedCount, StoneStockMovement::query()->where('type', StoneMovementType::USED_IN_ITEM)->count());
        $this->assertSame(0, StoneStockMovement::query()->where('type', StoneMovementType::RETURNED_FROM_ITEM)->count());

        $this->actingAs($admin)->put(route('branch.jewellery-items.update', $item), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 3]],
        ]))->assertRedirect();

        $this->assertSame(7, (int) $stone->fresh()->quantity);
        $this->assertSame($usedCount + 1, StoneStockMovement::query()->where('type', StoneMovementType::USED_IN_ITEM)->count());

        $this->actingAs($admin)->put(route('branch.jewellery-items.update', $item), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 1]],
        ]))->assertRedirect();

        $this->assertSame(9, (int) $stone->fresh()->quantity);
        $returned = StoneStockMovement::query()->where('type', StoneMovementType::RETURNED_FROM_ITEM)->first();
        $this->assertNotNull($returned);
        $this->assertSame(2, (int) $returned->quantity_change);
    }

    /**
     * Last remaining unit cannot be allocated twice; quantity never goes negative.
     */
    public function test_last_unit_second_save_fails(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 1);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'name' => 'First piece',
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 1]],
        ]))->assertRedirect();

        $this->actingAs($admin)
            ->from(route('branch.jewellery-items.create'))
            ->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
                'name' => 'Second piece',
                'stones' => [['uuid' => $stone->uuid, 'quantity' => 1]],
            ]))
            ->assertSessionHasErrors('stones');

        $this->assertSame(0, (int) $stone->fresh()->quantity);
        $this->assertSame(1, JewelleryItem::query()->count());
    }

    /**
     * Stone grams exceeding gross weight is rejected.
     */
    public function test_gross_weight_must_cover_stone_grams(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 5, '1.000');

        $this->actingAs($admin)
            ->from(route('branch.jewellery-items.create'))
            ->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
                'gross_weight' => '0.100',
                'metal_weight' => '0.050',
                'stones' => [['uuid' => $stone->uuid, 'quantity' => 1]],
            ]))
            ->assertSessionHasErrors('gross_weight');

        $this->assertSame(5, (int) $stone->fresh()->quantity);
        $this->assertSame(0, JewelleryItem::query()->count());
    }

    /**
     * Metal weight is required — it is the sale/net weight, not derived.
     */
    public function test_metal_weight_is_required(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();

        $this->actingAs($admin)
            ->from(route('branch.jewellery-items.create'))
            ->postJson(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
                'metal_weight' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('metal_weight');
    }

    /**
     * Typed metal grams cannot exceed the piece's gross weight.
     */
    public function test_metal_weight_cannot_exceed_gross(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();

        $this->actingAs($admin)
            ->from(route('branch.jewellery-items.create'))
            ->postJson(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
                'gross_weight' => '10.000',
                'metal_weight' => '10.500',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('metal_weight');
    }

    /**
     * sold and transferred cannot be set from this module.
     */
    public function test_sold_and_transferred_are_rejected(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();

        $this->actingAs($admin)
            ->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
                'status' => JewelleryItemStatus::SOLD->value,
            ]))
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId))->assertRedirect();
        $item = JewelleryItem::query()->first();

        $this->actingAs($admin)
            ->post(route('branch.jewellery-items.status', $item), [
                'status' => JewelleryItemStatus::TRANSFERRED->value,
            ])
            ->assertSessionHasErrors('status');
    }

    /**
     * Catalog edits do not rewrite snapshots on existing pieces.
     */
    public function test_catalog_edit_does_not_change_snapshots(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 2);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 1]],
        ]))->assertRedirect();

        $snapshotName = JewelleryItemStone::query()->value('stone_name_snapshot');
        $this->actingAs($admin)->put(route('branch.stones.update', $stone), [
            'name' => 'Renamed packet',
            'shape' => $stone->shape,
            'pricing_mode' => $stone->pricing_mode->value,
            'status' => $stone->status->value,
            'carat' => $stone->carat,
            'price_per_carat' => $stone->price_per_carat,
            'pieces' => $stone->quantity,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame($snapshotName, JewelleryItemStone::query()->value('stone_name_snapshot'));
        $this->assertNotSame('Renamed packet', $snapshotName);
    }

    /**
     * Delete returns allocated stones after the confirm path (HTTP delete).
     */
    public function test_delete_returns_allocated_stones(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 4);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 3]],
        ]))->assertRedirect();

        $item = JewelleryItem::query()->first();
        $this->actingAs($admin)->delete(route('branch.jewellery-items.destroy', $item))->assertRedirect(route('branch.jewellery-items.index'));

        $this->assertSoftDeleted($item);
        $this->assertSame(4, (int) $stone->fresh()->quantity);
        $this->assertSame(1, StoneStockMovement::query()->where('type', StoneMovementType::RETURNED_FROM_ITEM)->count());
    }

    /**
     * Other-branch uuid is 403.
     */
    public function test_cross_branch_item_is_forbidden(): void
    {
        [$branchA, $adminA, $category, $metalId, $purityId] = $this->branchContext();
        $branchB = Branch::factory()->create();
        $adminB = User::factory()->admin($branchB->id)->create();

        $this->actingAs($adminB)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId))->assertRedirect();
        $foreign = JewelleryItem::query()->withoutGlobalScopes()->first();

        $this->actingAs($adminA)->get(route('branch.jewellery-items.show', $foreign))->assertForbidden();
        $this->actingAs($adminA)->get('/branch/jewellery-items/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    /**
     * Public page omits price, staff, notes, and stone codes. Unavailable statuses leak nothing.
     */
    public function test_public_page_hides_internals_and_unavailable_items(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 2);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'name' => 'Emerald Cut Solitaire',
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 1]],
        ]))->assertRedirect();

        $item = JewelleryItem::query()->first();
        $url = '/item/'.$item->qr_token;

        $this->get($url)
            ->assertOk()
            ->assertSee('Emerald Cut Solitaire')
            ->assertDontSee('Internal bench notes')
            ->assertDontSee('Branch Admin Ali')
            ->assertDontSee($stone->stone_code)
            ->assertDontSee('$')
            ->assertDontSee('14,500')
            ->assertDontSee($item->uuid);

        $item->status = JewelleryItemStatus::DAMAGED;
        $item->save();

        $this->get($url)
            ->assertOk()
            ->assertSee('This item is no longer available')
            ->assertDontSee('Emerald Cut Solitaire')
            ->assertDontSee('Internal bench notes')
            ->assertDontSee($stone->stone_code);

        $this->get('/item/not-a-real-token')->assertNotFound();
    }

    /**
     * Public product route is tightly throttled per IP.
     */
    public function test_public_page_is_throttled(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId))->assertRedirect();
        $token = JewelleryItem::query()->value('qr_token');

        $limit = (int) config('annigems.jewellery.public_throttle_per_minute', 30);
        for ($i = 0; $i < $limit; $i++) {
            $this->get('/item/'.$token)->assertOk();
        }

        $this->get('/item/'.$token)->assertStatus(429);
    }

    /**
     * Cashier is view-only; inventory staff cannot delete; Admin can run every workflow.
     */
    public function test_role_permissions_for_jewellery(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $cashier = User::factory()->cashier($branch->id)->create();
        $inventory = User::factory()->inventoryStaff($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId))->assertRedirect();
        $item = JewelleryItem::query()->first();

        $this->actingAs($cashier)->get(route('branch.jewellery-items.index'))->assertOk();
        $this->actingAs($cashier)->get(route('branch.jewellery-items.show', $item))->assertOk();
        $this->actingAs($cashier)->get(route('branch.jewellery-items.create'))->assertForbidden();
        $this->actingAs($cashier)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, ['name' => 'Cashier piece']))->assertForbidden();
        $this->actingAs($cashier)->delete(route('branch.jewellery-items.destroy', $item))->assertForbidden();

        $this->actingAs($inventory)->get(route('branch.jewellery-items.create'))->assertOk();
        $this->actingAs($inventory)->delete(route('branch.jewellery-items.destroy', $item))->assertForbidden();

        $this->actingAs($admin)->get(route('branch.categories.index'))->assertOk();
        $this->actingAs($admin)->get(route('branch.metals.index'))->assertOk();
        $this->actingAs($admin)->get(route('branch.jewellery-items.edit', $item))->assertOk();
        $this->actingAs($admin)->post(route('branch.jewellery-items.status', $item), [
            'status' => JewelleryItemStatus::RESERVED->value,
        ])->assertRedirect();
        $this->assertSame(JewelleryItemStatus::RESERVED, $item->fresh()->status);
    }

    /**
     * In-use catalog rows cannot be deleted.
     */
    public function test_in_use_category_cannot_be_deleted(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId))->assertRedirect();

        $this->actingAs($admin)
            ->delete(route('branch.categories.destroy', $category))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('categories', ['uuid' => $category->uuid, 'deleted_at' => null]);
    }

    /**
     * stones.quantity assignment remains exclusive to StoneStockService.
     */
    public function test_quantity_writes_remain_inside_stock_service(): void
    {
        $hits = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_ends_with($path, 'Stone.php') || str_ends_with($path, 'StoneStockService.php')) {
                continue;
            }
            if (preg_match('/\$stone->quantity\s*=/', File::get($path))) {
                $hits[] = $path;
            }
        }

        $this->assertSame([], $hits, 'stones.quantity writes found outside StoneStockService: '.implode(', ', $hits));
    }

    /**
     * Allocating a stone on the jewellery form searches by name and scans
     * the packet barcode.
     */
    public function test_jewellery_stone_lookup_finds_by_name_and_barcode(): void
    {
        [$branch, $admin] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 3);

        $this->actingAs($admin)
            ->get(route('branch.jewellery-items.create'))
            ->assertOk()
            ->assertSee('data-stone-search', false)
            ->assertSee('data-ajax-collection="stones"', false);

        $this->actingAs($admin)
            ->getJson(route('branch.jewellery-items.stones.search', ['q' => $stone->name]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stones.0.uuid', $stone->uuid);

        $stones = $this->actingAs($admin)
            ->getJson(route('branch.jewellery-items.stones.search'))
            ->json('data.stones');

        $this->assertIsList($stones);
        $this->assertNotEmpty($stones);

        $this->actingAs($admin)
            ->postJson(route('branch.jewellery-items.stones.lookup'), ['code' => $stone->stone_code])
            ->assertOk()
            ->assertJsonPath('data.stone.uuid', $stone->uuid);

        $this->actingAs($admin)
            ->postJson(route('branch.jewellery-items.stones.lookup'), ['code' => $stone->barcode])
            ->assertOk()
            ->assertJsonPath('data.stone.uuid', $stone->uuid);
    }

    /**
     * Register a lot of any pricing mode through the stone form.
     *
     * @param  array<string, mixed>  $fields
     */
    private function lot(User $admin, array $fields): Stone
    {
        $this->actingAs($admin)->post(route('branch.stones.store'), array_merge([
            'name' => 'Lot '.uniqid(),
            'status' => 'in_stock',
        ], $fields))->assertRedirect();

        return Stone::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /**
     * Each stone is priced in its own unit: carat, piece or gram.
     */
    public function test_create_prices_each_stone_in_its_own_unit(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $carat = $this->lot($admin, ['pricing_mode' => 'per_carat', 'carat' => '0.200', 'price_per_carat' => '100', 'pieces' => 5]);
        $piece = $this->lot($admin, ['pricing_mode' => 'per_piece', 'price_per_piece' => '7', 'pieces' => 10]);
        $gram = $this->lot($admin, ['pricing_mode' => 'per_gram', 'weight_grams' => '10', 'price_per_gram' => '5']);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [
                ['uuid' => $carat->uuid, 'quantity' => 2],
                ['uuid' => $piece->uuid, 'quantity' => 3],
                ['uuid' => $gram->uuid, 'quantity' => 1, 'weight_grams' => '4.5'],
            ],
        ]))->assertRedirect();

        $item = JewelleryItem::query()->firstOrFail();
        $rows = JewelleryItemStone::query()->where('jewellery_item_id', $item->id)->get()->keyBy('stone_id');

        // 0.2 ct x 100 = 20 per piece, x 2 pieces.
        $this->assertSame('per_carat', $rows[$carat->id]->pricing_mode->value);
        $this->assertSame('100.000', (string) $rows[$carat->id]->unit_rate);
        $this->assertSame('40.000', (string) $rows[$carat->id]->line_cost);

        $this->assertSame('per_piece', $rows[$piece->id]->pricing_mode->value);
        $this->assertSame('21.000', (string) $rows[$piece->id]->line_cost);

        // A gram lot is one row, weighed: 4.5 g x 5.
        $this->assertSame('per_gram', $rows[$gram->id]->pricing_mode->value);
        $this->assertSame(1, (int) $rows[$gram->id]->quantity);
        $this->assertSame('4.500', (string) $rows[$gram->id]->weight_grams);
        $this->assertSame('22.500', (string) $rows[$gram->id]->line_cost);

        $this->assertSame(3, (int) $carat->fresh()->quantity);
        $this->assertSame(7, (int) $piece->fresh()->quantity);
        $this->assertSame('5.500', (string) $gram->fresh()->weight_grams);

        $used = StoneStockMovement::query()
            ->where('stone_id', $gram->id)
            ->where('type', StoneMovementType::USED_IN_ITEM)
            ->firstOrFail();
        $this->assertSame('-4.500', (string) $used->weight_change);

        // 0.4 ct = 0.080 g of carat stones, plus the 4.5 g weighed lot.
        $this->assertSame('4.580', (string) $item->fresh()->stone_weight_grams);
        $this->assertSame('0.400', (string) $item->fresh()->stone_weight_carat);
    }

    /**
     * A gram lot needs grams, and cannot use more than it holds.
     */
    public function test_gram_lot_needs_grams_within_stock(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $gram = $this->lot($admin, ['pricing_mode' => 'per_gram', 'weight_grams' => '3', 'price_per_gram' => '5']);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $gram->uuid, 'quantity' => 1]],
        ]))->assertSessionHasErrors('stones');

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $gram->uuid, 'quantity' => 1, 'weight_grams' => '3.5']],
        ]))->assertSessionHasErrors('stones');

        $this->assertSame(0, JewelleryItem::query()->count());
        $this->assertSame('3.000', (string) $gram->fresh()->weight_grams);
    }

    /**
     * A rate edit on the lot does not reprice a piece unless more of it is used.
     */
    public function test_edit_keeps_the_snapshot_rate_until_quantity_grows(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 10);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 3]],
        ]))->assertRedirect();
        $item = JewelleryItem::query()->firstOrFail();

        $stone->forceFill(['price_per_carat' => '200'])->save();

        $this->actingAs($admin)->put(route('branch.jewellery-items.update', $item), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 2]],
        ]))->assertRedirect();

        $row = JewelleryItemStone::query()->firstOrFail();
        $this->assertSame('100.000', (string) $row->unit_rate);
        $this->assertSame('40.000', (string) $row->line_cost);

        $this->actingAs($admin)->put(route('branch.jewellery-items.update', $item), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 4]],
        ]))->assertRedirect();

        $row = JewelleryItemStone::query()->firstOrFail();
        $this->assertSame('200.000', (string) $row->unit_rate);
        $this->assertSame('160.000', (string) $row->line_cost);
    }

    /**
     * Deleting a piece gives weighed stone back as grams.
     */
    public function test_delete_returns_grams_of_a_gram_lot(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $gram = $this->lot($admin, ['pricing_mode' => 'per_gram', 'weight_grams' => '10', 'price_per_gram' => '5']);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $gram->uuid, 'quantity' => 1, 'weight_grams' => '4']],
        ]))->assertRedirect();
        $this->assertSame('6.000', (string) $gram->fresh()->weight_grams);

        $item = JewelleryItem::query()->firstOrFail();
        $this->actingAs($admin)->delete(route('branch.jewellery-items.destroy', $item))->assertRedirect();

        $this->assertSame('10.000', (string) $gram->fresh()->weight_grams);
    }

    /**
     * Sales lines start from the cost snapshot, but only for users who may see cost.
     */
    public function test_sales_line_loads_stone_price_for_cost_users_only(): void
    {
        [$branch, $admin, $category, $metalId, $purityId] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 10);

        $this->actingAs($admin)->post(route('branch.jewellery-items.store'), $this->itemPayload($category, $metalId, $purityId, [
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 2]],
        ]))->assertRedirect();
        $item = JewelleryItem::query()->with('itemStones')->firstOrFail();

        $this->actingAs($admin);
        $line = app(\App\Services\SalesLookupService::class)->jewelleryPayload($item);
        $this->assertSame('20.00', $line['stones'][0]['price_per_piece']);

        $cashier = User::factory()->cashier($branch->id)->create();
        $this->actingAs($cashier);
        $line = app(\App\Services\SalesLookupService::class)->jewelleryPayload($item);
        $this->assertSame('0.00', $line['stones'][0]['price_per_piece']);
    }

    /**
     * The stone lookup carries the lot's rate for users who may see cost.
     */
    public function test_stone_lookup_includes_rate_for_cost_users(): void
    {
        [$branch, $admin] = $this->branchContext();
        $stone = $this->stockedStone($admin, $branch, 5);

        $this->actingAs($admin)
            ->postJson(route('branch.jewellery-items.stones.lookup'), ['code' => $stone->stone_code])
            ->assertOk()
            ->assertJsonPath('data.stone.rate', '100.000')
            ->assertJsonPath('data.stone.rate_unit', 'ct');
    }

    /**
     * A loose-stone sales line starts from the lot's price per piece, for cost users only.
     */
    public function test_loose_stone_line_loads_price_for_cost_users_only(): void
    {
        [$branch, $admin] = $this->branchContext();
        $carat = $this->stockedStone($admin, $branch, 5);
        $piece = $this->lot($admin, ['pricing_mode' => 'per_piece', 'price_per_piece' => '7.5', 'pieces' => 4]);
        $gram = $this->lot($admin, ['pricing_mode' => 'per_gram', 'weight_grams' => '10', 'price_per_gram' => '5']);

        $service = app(\App\Services\SalesLookupService::class);

        $this->actingAs($admin);
        // 0.2 ct x 100 per carat is 20 a piece.
        $this->assertSame('20.00', $service->stonePayload($carat)['price_per_piece']);
        $this->assertSame('7.50', $service->stonePayload($piece)['price_per_piece']);
        // A weighed lot is sold by the gram: the price field carries the price of one gram.
        $this->assertSame('5.00', $service->stonePayload($gram)['price_per_piece']);

        $this->actingAs(User::factory()->cashier($branch->id)->create());
        $this->assertSame('0.00', $service->stonePayload($carat)['price_per_piece']);
    }
}
