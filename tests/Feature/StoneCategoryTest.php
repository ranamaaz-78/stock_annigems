<?php

namespace Tests\Feature;

use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use App\Models\Branch;
use App\Models\Stone;
use App\Models\StoneCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Material folders.
 *
 * A stone's name is also its category: CITIRINO lots belong in the CITIRINO
 * folder. Folders are never typed in by hand, so these tests cover the rules
 * that decide when one is created, reused, or moved between.
 */
class StoneCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * A branch admin and their branch.
     *
     * @return array{0: Branch, 1: User}
     */
    private function branchAdmin(): array
    {
        $branch = Branch::factory()->create();

        return [$branch, User::factory()->admin($branch->id)->create()];
    }

    /**
     * The lots list honours the "Show" size, offers Edit on each row, and
     * says which rows are on screen.
     */
    public function test_lots_list_has_page_size_choice_and_edit_action(): void
    {
        [$branch, $admin] = $this->branchAdmin();
        $category = StoneCategory::query()->create([
            'branch_id' => $branch->id,
            'name' => 'AMATISTA',
            'match_key' => StoneCategory::matchKeyFor('AMATISTA'),
            'is_active' => true,
        ]);
        Stone::factory()->count(12)->for($branch)->create(['stone_category_id' => $category->id, 'name' => 'AMATISTA']);

        $url = fn (array $query) => route('branch.stones.index', array_merge(['category' => $category->uuid], $query));

        // 10 per page: first page of two, with the size choice and Edit links.
        $first = $this->actingAs($admin)->get($url(['per_page' => 10]))->assertOk();
        $first->assertSee('Showing', false)->assertSee('data-per-page', false);
        $first->assertSee('aria-label="Edit"', false);
        $this->assertCount(10, $first->viewData('stones')->items());

        // "All" shows every lot on one page and keeps the choice visible.
        $all = $this->actingAs($admin)->get($url(['per_page' => 'all']))->assertOk();
        $this->assertCount(12, $all->viewData('stones')->items());
        $all->assertSee('data-per-page', false);

        // A made-up size falls back to the default instead of being obeyed.
        $odd = $this->actingAs($admin)->get($url(['per_page' => 999999]))->assertOk();
        $this->assertSame(20, $odd->viewData('stones')->perPage());
    }

    /**
     * A folder shows its stock in each lot's own unit instead of "0 pcs" for
     * gram lots: pieces, carat and grams, only where they exist.
     */
    public function test_folder_tiles_show_stock_in_each_lots_own_unit(): void
    {
        [$branch, $admin] = $this->branchAdmin();

        $register = fn (array $payload) => $this->actingAs($admin)
            ->post(route('branch.stones.store'), array_merge([
                'status' => StoneStatus::IN_STOCK->value,
                'pairs' => 0,
            ], $payload))->assertRedirect();

        // Gram-only material.
        $register(['name' => 'CORAL', 'pricing_mode' => StonePricingMode::PER_GRAM->value, 'weight_grams' => '150.5', 'price_per_gram' => '2']);
        // Per-carat material: 10 ct in stock (no pieces).
        $register(['name' => 'AMATISTA', 'pricing_mode' => StonePricingMode::PER_CARAT->value, 'carat' => '10', 'price_per_carat' => '3']);
        // Piece-only material.
        $register(['name' => 'HEART', 'pricing_mode' => StonePricingMode::PER_PIECE->value, 'price_per_piece' => '9', 'pieces' => 71]);

        $html = $this->actingAs($admin)->get(route('branch.stones.index'))->assertOk()->getContent();

        $this->assertStringContainsString('150.5 g', $html);
        $this->assertStringContainsString('10 ct', $html);
        $this->assertStringContainsString('71 pcs', $html);
        // The gram-only folder no longer claims zero pieces.
        $this->assertStringNotContainsString('0 pcs', $html);
    }

    /**
     * Registration payload for a per-piece lot.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
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
        ], $overrides);
    }

    public function test_registering_an_unseen_material_creates_its_folder(): void
    {
        [, $admin] = $this->branchAdmin();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload())->assertRedirect();

        $category = StoneCategory::query()->firstOrFail();

        $this->assertSame('CITIRINO', $category->name);
        $this->assertSame('CITIRINO', $category->match_key);
        $this->assertSame($category->id, Stone::query()->firstOrFail()->stone_category_id);
    }

    public function test_a_known_material_reuses_its_folder(): void
    {
        [, $admin] = $this->branchAdmin();

        // Casing and stray spacing differ constantly between supplier rows.
        foreach (['CITIRINO', 'citirino', '  Citirino  '] as $name) {
            $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload(['name' => $name]))
                ->assertRedirect();
        }

        $this->assertSame(1, StoneCategory::query()->count());
        $this->assertSame(3, StoneCategory::query()->firstOrFail()->stones()->count());

        // The first spelling seen is the one the folder keeps.
        $this->assertSame('CITIRINO', StoneCategory::query()->firstOrFail()->name);
    }

    public function test_different_spellings_stay_in_separate_folders(): void
    {
        [, $admin] = $this->branchAdmin();

        // Only a human can decide these are the same stone.
        foreach (['CITIRINO', 'CITRINO'] as $name) {
            $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload(['name' => $name]))
                ->assertRedirect();
        }

        $this->assertSame(['CITIRINO', 'CITRINO'], StoneCategory::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_renaming_a_lot_moves_it_to_the_new_folder(): void
    {
        [, $admin] = $this->branchAdmin();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload())->assertRedirect();
        $stone = Stone::query()->firstOrFail();
        $original = $stone->stone_category_id;

        $this->actingAs($admin)->put(route('branch.stones.update', $stone), $this->payload([
            'name' => 'AMATISTA',
            'is_active' => 1,
        ]))->assertRedirect();

        $stone->refresh();
        $moved = StoneCategory::query()->where('name', 'AMATISTA')->firstOrFail();

        $this->assertSame($moved->id, $stone->stone_category_id);
        $this->assertNotSame($original, $stone->stone_category_id);

        // The emptied folder stays: the lots that left still reference it
        // in their audit history.
        $this->assertSame(2, StoneCategory::query()->count());
    }

    public function test_folders_are_branch_scoped(): void
    {
        [, $adminA] = $this->branchAdmin();
        [, $adminB] = $this->branchAdmin();

        $this->actingAs($adminA)->post(route('branch.stones.store'), $this->payload())->assertRedirect();
        $this->actingAs($adminB)->post(route('branch.stones.store'), $this->payload())->assertRedirect();

        // Each branch keeps its own CITIRINO folder, and the global scope
        // means neither admin can see the other's.
        $this->assertSame(2, StoneCategory::query()->withoutGlobalScopes()->count());

        $this->actingAs($adminA);
        $this->assertSame(1, StoneCategory::query()->count());
        $this->assertSame((int) $adminA->branch_id, (int) StoneCategory::query()->firstOrFail()->branch_id);
    }

    public function test_the_listing_opens_on_folders(): void
    {
        [, $admin] = $this->branchAdmin();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload())->assertRedirect();
        $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload(['name' => 'AMATISTA']))
            ->assertRedirect();

        $this->actingAs($admin)->get(route('branch.stones.index'))
            ->assertOk()
            ->assertSee('folder-tile__name', false)
            ->assertSee('CITIRINO')
            ->assertSee('AMATISTA')
            // Individual lot codes belong inside a folder, not on the grid.
            ->assertDontSee('STN-000001');
    }

    public function test_opening_a_folder_shows_only_its_lots(): void
    {
        [, $admin] = $this->branchAdmin();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload())->assertRedirect();
        $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload(['name' => 'AMATISTA']))
            ->assertRedirect();

        $citirino = StoneCategory::query()->where('name', 'CITIRINO')->firstOrFail();

        $this->actingAs($admin)->get(route('branch.stones.index', ['category' => $citirino->uuid]))
            ->assertOk()
            ->assertSee('STN-000001')
            ->assertDontSee('STN-000002');
    }

    public function test_a_folder_from_another_branch_shows_nothing(): void
    {
        [, $adminA] = $this->branchAdmin();
        [, $adminB] = $this->branchAdmin();

        $this->actingAs($adminA)->post(route('branch.stones.store'), $this->payload())->assertRedirect();
        $foreign = StoneCategory::query()->withoutGlobalScopes()->firstOrFail();

        // The uuid is valid but belongs elsewhere: branch B must not see it.
        $this->actingAs($adminB)->get(route('branch.stones.index', ['category' => $foreign->uuid]))
            ->assertOk()
            ->assertDontSee('STN-000001');
    }

    public function test_an_imported_sheet_files_every_lot(): void
    {
        [, $admin] = $this->branchAdmin();

        // The importer goes through the same service as the manual form.
        $rows = [
            $this->payload(['name' => 'CITIRINO']),
            $this->payload(['name' => 'CITIRINO']),
            $this->payload(['name' => 'AMATISTA']),
        ];

        app(\App\Services\StoneService::class)->registerMany($rows, $admin);

        $this->assertSame(2, StoneCategory::query()->count());
        $this->assertSame(0, Stone::query()->whereNull('stone_category_id')->count());
        $this->assertSame(2, StoneCategory::query()->where('name', 'CITIRINO')->firstOrFail()->stones()->count());
    }
}
