<?php

namespace Tests\Feature;

use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Stone;
use App\Models\StoneCategory;
use App\Models\StoneStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renaming and deleting material folders: branch admin only, a rename leaves
 * every lot's own name alone, and a delete is all-or-nothing.
 */
class StoneFolderTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->admin($this->branch->id)->create();
    }

    /**
     * Register a lot the way the form does, so it is filed into its folder.
     */
    private function lot(string $name, int $pieces = 10): Stone
    {
        $this->actingAs($this->admin)->post(route('branch.stones.store'), [
            'name' => $name,
            'status' => 'in_stock',
            'pricing_mode' => 'per_piece',
            'price_per_piece' => '7',
            'pieces' => $pieces,
        ])->assertRedirect();

        return Stone::query()->latest('id')->firstOrFail();
    }

    private function folder(string $name): StoneCategory
    {
        return StoneCategory::query()->where('name', $name)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(Stone $stone): array
    {
        return [
            'walkin_name' => 'Maria Garcia',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'discount_value' => 0,
            'tax_type' => 'none',
            'tax_rate' => 0,
            'apply_tax' => false,
            'lines' => [['line_type' => 'loose_stone', 'stone_id' => $stone->id, 'quantity' => 2]],
        ];
    }

    public function test_rename_changes_the_label_and_leaves_lot_names_alone(): void
    {
        $lot = $this->lot('CITIRINO');
        $folder = $this->folder('CITIRINO');

        $this->actingAs($this->admin)
            ->put(route('branch.stone-folders.update', $folder), ['name' => 'CITRINE'])
            ->assertRedirect(route('branch.stones.index', ['category' => $folder->uuid]))
            ->assertSessionHas('success');

        $this->assertSame('CITRINE', $folder->fresh()->name);
        $this->assertSame('CITIRINO', $lot->fresh()->name);
        $this->assertSame($folder->id, $lot->fresh()->stone_category_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stone_category.renamed']);
    }

    public function test_lots_under_the_old_or_new_spelling_still_land_in_the_renamed_folder(): void
    {
        $this->lot('CITIRINO');
        $folder = $this->folder('CITIRINO');
        $this->actingAs($this->admin)->put(route('branch.stone-folders.update', $folder), ['name' => 'CITRINE']);

        $old = $this->lot('citirino');
        $new = $this->lot('Citrine');

        $this->assertSame($folder->id, $old->fresh()->stone_category_id);
        $this->assertSame($folder->id, $new->fresh()->stone_category_id);
        $this->assertSame(1, StoneCategory::query()->count());
    }

    public function test_rename_onto_another_folder_is_refused(): void
    {
        $this->lot('CITIRINO');
        $this->lot('CITRINE');

        $this->actingAs($this->admin)
            ->put(route('branch.stone-folders.update', $this->folder('CITIRINO')), ['name' => 'citrine'])
            ->assertSessionHas('error');

        $this->assertSame('CITIRINO', $this->folder('CITIRINO')->name);
    }

    public function test_delete_removes_every_lot_and_writes_off_stock(): void
    {
        $first = $this->lot('AMATISTA', 10);
        $second = $this->lot('AMATISTA', 5);
        $other = $this->lot('CITRINE');
        $folder = $this->folder('AMATISTA');

        $this->actingAs($this->admin)
            ->delete(route('branch.stone-folders.destroy', $folder))
            ->assertRedirect(route('branch.stones.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('stones', ['id' => $first->id]);
        $this->assertSoftDeleted('stones', ['id' => $second->id]);
        $this->assertNotSoftDeleted('stones', ['id' => $other->id]);
        $this->assertSoftDeleted('stone_categories', ['id' => $folder->id]);
        $this->assertSame(2, StoneStockMovement::query()->where('type', StoneMovementType::ADJUSTED)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'stone_category.deleted']);

        // The name is free again: a new lot makes a fresh, visible folder.
        $again = $this->lot('AMATISTA');
        $this->assertNotSame($folder->id, $again->fresh()->stone_category_id);
        $this->assertNull($again->fresh()->category?->deleted_at);
    }

    public function test_delete_is_refused_whole_when_any_lot_is_held(): void
    {
        $held = $this->lot('AMATISTA');
        $free = $this->lot('AMATISTA');
        $folder = $this->folder('AMATISTA');
        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket($held))->assertOk();

        $this->actingAs($this->admin)
            ->delete(route('branch.stone-folders.destroy', $folder))
            ->assertRedirect(route('branch.stones.index', ['category' => $folder->uuid]))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('stones', ['id' => $held->id]);
        $this->assertNotSoftDeleted('stones', ['id' => $free->id]);
        $this->assertNotSoftDeleted('stone_categories', ['id' => $folder->id]);
    }

    public function test_only_a_branch_admin_may_rename_or_delete(): void
    {
        $this->lot('AMATISTA');
        $folder = $this->folder('AMATISTA');
        $manager = User::factory()->manager($this->branch->id)->create();
        $staff = User::factory()->inventoryStaff($this->branch->id)->create();

        foreach ([$manager, $staff] as $user) {
            $this->actingAs($user)->put(route('branch.stone-folders.update', $folder), ['name' => 'X'])->assertForbidden();
            $this->actingAs($user)->delete(route('branch.stone-folders.destroy', $folder))->assertForbidden();
            $this->actingAs($user)->get(route('branch.stones.index', ['category' => $folder->uuid]))
                ->assertOk()->assertDontSee('Rename folder');
        }

        $this->actingAs($this->admin)->get(route('branch.stones.index', ['category' => $folder->uuid]))
            ->assertOk()->assertSee('Rename folder')->assertSee('data-confirm-delete-folder', false);
        $this->assertSame('AMATISTA', $folder->fresh()->name);
    }

    public function test_folder_tiles_carry_rename_and_delete_only_for_an_admin(): void
    {
        $this->lot('AMATISTA');
        $folder = $this->folder('AMATISTA');
        $manager = User::factory()->manager($this->branch->id)->create();

        $this->actingAs($this->admin)->get(route('branch.stones.index'))->assertOk()
            ->assertSee('data-folder-rename', false)
            ->assertSee('data-confirm-delete-folder', false)
            ->assertSee(route('branch.stone-folders.destroy', $folder), false)
            ->assertSee('class="folder-tile"', false);

        $this->actingAs($manager)->get(route('branch.stones.index'))->assertOk()
            ->assertSee('class="folder-tile"', false)
            ->assertDontSee('data-folder-rename', false)
            ->assertDontSee('data-confirm-delete-folder', false)
            ->assertDontSee(route('branch.stone-folders.destroy', $folder), false);
    }

    public function test_rename_and_delete_from_the_list_return_to_the_list(): void
    {
        $this->lot('CITIRINO');
        $this->lot('AMATISTA');
        $list = route('branch.stones.index', ['q' => 'A']);

        $this->actingAs($this->admin)->from($list)
            ->put(route('branch.stone-folders.update', $this->folder('CITIRINO')), ['name' => 'CITRINE', 'return' => 'folders'])
            ->assertRedirect($list)
            ->assertSessionHas('success');
        $this->assertSame('CITRINE', StoneCategory::query()->where('match_key', 'CITIRINO')->value('name'));

        // A refused delete from the list also stays on the list.
        $held = Stone::query()->where('name', 'AMATISTA')->firstOrFail();
        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket($held))->assertOk();

        $this->actingAs($this->admin)->from($list)
            ->delete(route('branch.stone-folders.destroy', $this->folder('AMATISTA')), ['return' => 'folders'])
            ->assertRedirect($list)
            ->assertSessionHas('error');
        $this->assertNotSoftDeleted('stones', ['id' => $held->id]);
    }

    public function test_another_branchs_folder_is_out_of_reach(): void
    {
        $this->lot('AMATISTA');
        $folder = $this->folder('AMATISTA');
        $outsider = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($outsider)->put(route('branch.stone-folders.update', $folder), ['name' => 'X'])->assertForbidden();
        $this->actingAs($outsider)->delete(route('branch.stone-folders.destroy', $folder))->assertForbidden();
    }
}
