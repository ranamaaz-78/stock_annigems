<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Stone;
use App\Models\StoneCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The stone list has three explicit scopes, so filtering never silently
 * changes what is listed: Folders (search materials only), All lots (every
 * lot, with a folder picker) and one Folder (its own lots).
 */
class StoneListScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->admin = User::factory()->admin(Branch::factory()->create()->id)->create();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function lot(string $name, array $fields): Stone
    {
        $this->actingAs($this->admin)->post(route('branch.stones.store'), array_merge(['name' => $name, 'status' => 'in_stock'], $fields))->assertRedirect();

        return Stone::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** @return array{0: Stone, 1: Stone, 2: Stone} */
    private function lots(): array
    {
        return [
            $this->lot('AMETHYST', ['pricing_mode' => 'per_piece', 'price_per_piece' => '3', 'pieces' => 5]),
            $this->lot('AMETHYST', ['pricing_mode' => 'per_gram', 'weight_grams' => '40', 'price_per_gram' => '2']),
            $this->lot('TOPAZ', ['pricing_mode' => 'per_piece', 'price_per_piece' => '9', 'pieces' => 7]),
        ];
    }

    public function test_the_folder_grid_only_searches_materials_and_offers_no_lot_filters(): void
    {
        [$a, , $topaz] = $this->lots();

        $page = $this->actingAs($this->admin)->get(route('branch.stones.index'))->assertOk();
        $page->assertSee('Search materials', false)
            ->assertSee('class="folder-tile"', false)
            ->assertDontSee('name="pricing_mode"', false)
            ->assertDontSee('name="stock"', false)
            ->assertSee('stone-scope__tab is-on', false);
        $this->assertSame('folders', $page->viewData('mode'));

        // Searching on the grid narrows the folders and stays on the grid.
        $found = $this->actingAs($this->admin)->get(route('branch.stones.index', ['q' => 'TOPAZ']))->assertOk();
        $this->assertSame('folders', $found->viewData('mode'));
        $names = $found->viewData('categories')->pluck('name');
        $this->assertSame(['TOPAZ'], $names->all());
    }

    public function test_one_folder_lists_only_its_lots_and_every_filter_stays_inside_it(): void
    {
        [$piece, $gram, $topaz] = $this->lots();
        $folder = StoneCategory::query()->where('name', 'AMETHYST')->firstOrFail();

        $page = $this->actingAs($this->admin)->get(route('branch.stones.index', ['category' => $folder->uuid]))->assertOk();
        $this->assertSame('folder', $page->viewData('mode'));
        $this->assertEqualsCanonicalizing([$piece->stone_code, $gram->stone_code], $page->viewData('stones')->pluck('stone_code')->all());
        $page->assertSee('name="category" value="'.$folder->uuid.'"', false)
            ->assertSee('name="pricing_mode"', false)
            ->assertDontSee('<select name="category"', false)
            // Reset stays in the folder.
            ->assertSee('href="'.route('branch.stones.index', ['category' => $folder->uuid]).'" class="btn btn--secondary">Reset', false);

        // A pricing filter inside the folder never shows the other material's lots.
        $filtered = $this->actingAs($this->admin)->get(route('branch.stones.index', ['category' => $folder->uuid, 'pricing_mode' => 'per_piece']))->assertOk();
        $this->assertSame([$piece->stone_code], $filtered->viewData('stones')->pluck('stone_code')->all());
    }

    public function test_all_lots_is_its_own_scope_with_a_folder_picker(): void
    {
        [$piece, $gram, $topaz] = $this->lots();
        $folder = StoneCategory::query()->where('name', 'TOPAZ')->firstOrFail();

        $page = $this->actingAs($this->admin)->get(route('branch.stones.index', ['view' => 'lots']))->assertOk();
        $this->assertSame('lots', $page->viewData('mode'));
        $this->assertCount(3, $page->viewData('stones'));
        $page->assertSee('<select name="category"', false)->assertSee('name="view" value="lots"', false)
            ->assertSee('href="'.route('branch.stones.index', ['view' => 'lots']).'" class="btn btn--secondary">Reset', false);

        // The picker narrows to one folder without leaving All lots.
        $one = $this->actingAs($this->admin)->get(route('branch.stones.index', ['view' => 'lots', 'category' => $folder->uuid]))->assertOk();
        $this->assertSame('folder', $one->viewData('mode'), 'a chosen folder is the folder scope');
        $this->assertSame([$topaz->stone_code], $one->viewData('stones')->pluck('stone_code')->all());

        // Filters across all lots.
        $grams = $this->actingAs($this->admin)->get(route('branch.stones.index', ['view' => 'lots', 'pricing_mode' => 'per_gram']))->assertOk();
        $this->assertSame([$gram->stone_code], $grams->viewData('stones')->pluck('stone_code')->all());

        // An old link with only lot filters still means All lots.
        $legacy = $this->actingAs($this->admin)->get(route('branch.stones.index', ['pricing_mode' => 'per_piece']))->assertOk();
        $this->assertSame('lots', $legacy->viewData('mode'));
        $this->assertEqualsCanonicalizing([$piece->stone_code, $topaz->stone_code], $legacy->viewData('stones')->pluck('stone_code')->all());
    }

    public function test_a_folders_filters_offer_only_what_its_lots_carry(): void
    {
        $a = $this->lot('AMETHYST', ['pricing_mode' => 'per_piece', 'price_per_piece' => '3', 'pieces' => 5, 'tray_no' => 'A1', 'shape' => 'ROUND']);
        $this->lot('AMETHYST', ['pricing_mode' => 'per_piece', 'price_per_piece' => '3', 'pieces' => 5, 'tray_no' => 'A2', 'shape' => 'OVAL']);
        $this->lot('TOPAZ', ['pricing_mode' => 'per_gram', 'weight_grams' => '10', 'price_per_gram' => '2', 'tray_no' => 'Z9', 'shape' => 'PEAR']);
        $folder = StoneCategory::query()->where('name', 'AMETHYST')->firstOrFail();

        $inside = $this->actingAs($this->admin)->get(route('branch.stones.index', ['category' => $folder->uuid]))->assertOk();
        $this->assertSame(['A1', 'A2'], $inside->viewData('trays'));
        $this->assertSame(['OVAL', 'ROUND'], $inside->viewData('shapes'));
        $this->assertSame(['per_piece'], array_keys($inside->viewData('pricingModes')));
        $inside->assertSee('value="A1"', false)->assertDontSee('value="Z9"', false)->assertDontSee('value="PEAR"', false)
            ->assertDontSee('name="pricing_mode"', false);

        // Across all lots every tray, shape and pricing mode of the branch is offered.
        $all = $this->actingAs($this->admin)->get(route('branch.stones.index', ['view' => 'lots']))->assertOk();
        $this->assertSame(['A1', 'A2', 'Z9'], $all->viewData('trays'));
        $this->assertEqualsCanonicalizing(['per_piece', 'per_gram'], array_keys($all->viewData('pricingModes')));
        $all->assertSee('name="pricing_mode"', false);
    }

    public function test_a_lot_reads_out_of_stock_when_it_runs_out_and_in_stock_again_when_restocked(): void
    {
        $stones = app(\App\Services\StoneStockService::class);
        $piece = $this->lot('AMETHYST', ['pricing_mode' => 'per_piece', 'price_per_piece' => '3', 'pieces' => 2]);
        $gram = $this->lot('TOPAZ', ['pricing_mode' => 'per_gram', 'weight_grams' => '4', 'price_per_gram' => '2']);
        $this->assertSame(\App\Enums\StoneStatus::IN_STOCK, $piece->status);

        // The last pieces leave: it is out of stock by itself.
        $stones->apply($piece->fresh(), \App\Enums\StoneMovementType::SOLD, -2, $this->admin);
        $stones->applyWeight($gram->fresh(), \App\Enums\StoneMovementType::SOLD, -4, $this->admin);
        $this->assertSame(\App\Enums\StoneStatus::OUT_OF_STOCK, $piece->fresh()->status);
        $this->assertSame(\App\Enums\StoneStatus::OUT_OF_STOCK, $gram->fresh()->status);

        $page = $this->actingAs($this->admin)->get(route('branch.stones.index', ['view' => 'lots']))->assertOk();
        $page->assertSee('Out of stock');

        // The status filter offers it, and finds exactly these lots.
        $found = $this->actingAs($this->admin)->get(route('branch.stones.index', ['view' => 'lots', 'status' => 'out_of_stock']))->viewData('stones')->pluck('stone_code')->all();
        $this->assertEqualsCanonicalizing([$piece->stone_code, $gram->stone_code], $found);

        // A return puts stock back: in stock again.
        $stones->apply($piece->fresh(), \App\Enums\StoneMovementType::RETURNED_FROM_SALE, 1, $this->admin);
        $stones->applyWeight($gram->fresh(), \App\Enums\StoneMovementType::RETURNED_FROM_SALE, 1.5, $this->admin);
        $this->assertSame(\App\Enums\StoneStatus::IN_STOCK, $piece->fresh()->status);
        $this->assertSame(\App\Enums\StoneStatus::IN_STOCK, $gram->fresh()->status);
    }

    public function test_a_pending_lot_is_left_alone_and_the_form_never_offers_out_of_stock(): void
    {
        $pending = $this->lot('ONYX', ['pricing_mode' => 'per_piece', 'price_per_piece' => '1', 'pieces' => 3, 'status' => 'pending']);
        app(\App\Services\StoneStockService::class)->apply($pending->fresh(), \App\Enums\StoneMovementType::SOLD, -3, $this->admin);
        $this->assertSame(\App\Enums\StoneStatus::PENDING, $pending->fresh()->status, 'figures still missing: not decided by stock');

        $out = $this->lot('JADE', ['pricing_mode' => 'per_piece', 'price_per_piece' => '1', 'pieces' => 2]);
        app(\App\Services\StoneStockService::class)->apply($out->fresh(), \App\Enums\StoneMovementType::SOLD, -2, $this->admin);
        $this->assertSame(\App\Enums\StoneStatus::OUT_OF_STOCK, $out->fresh()->status);

        // The edit form offers In stock / Pending only, and shows an out-of-stock lot as In stock.
        $form = $this->actingAs($this->admin)->get(route('branch.stones.edit', $out))->assertOk();
        $form->assertDontSee('value="out_of_stock"', false);

        // Giving it pieces on edit puts it back in stock.
        $this->actingAs($this->admin)->put(route('branch.stones.update', $out), [
            'name' => 'JADE', 'status' => 'in_stock', 'pricing_mode' => 'per_piece', 'price_per_piece' => '2', 'pieces' => 4, 'is_active' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(\App\Enums\StoneStatus::IN_STOCK, $out->fresh()->status);
    }

    public function test_the_stock_filter_judges_gram_lots_by_weight_not_pieces(): void
    {
        [$piece, $gram] = $this->lots();
        $empty = $this->lot('JADE', ['pricing_mode' => 'per_piece', 'price_per_piece' => '1', 'pieces' => 1]);
        app(\App\Services\StoneStockService::class)->apply($empty, \App\Enums\StoneMovementType::ADJUSTED, -1, $this->admin);

        $in = $this->actingAs($this->admin)->get(route('branch.stones.index', ['view' => 'lots', 'stock' => 'in']))->viewData('stones')->pluck('stone_code')->all();
        $out = $this->actingAs($this->admin)->get(route('branch.stones.index', ['view' => 'lots', 'stock' => 'out']))->viewData('stones')->pluck('stone_code')->all();

        $this->assertContains($gram->stone_code, $in, 'a gram lot with weight is in stock');
        $this->assertNotContains($gram->stone_code, $out, 'and is never out of stock just because it has no pieces');
        $this->assertSame([$empty->stone_code], $out);
    }
}
