<?php

namespace Tests\Feature;

use App\Enums\JewelleryItemStatus;
use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Creditor;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\MetalType;
use App\Models\Performa;
use App\Models\Purity;
use App\Models\Quotation;
use App\Models\Stone;
use App\Models\StoneStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting stones and jewellery pieces: a stone is soft-deleted (remaining
 * stock written off) unless an open document still needs it; a piece only
 * while it is not reserved, sold, on memo, delivered or transferred.
 */
class DeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->seed(\Database\Seeders\MetalPuritySeeder::class);
        $this->admin = User::factory()->admin(Branch::factory()->create()->id)->create();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function lot(array $fields): Stone
    {
        $this->actingAs($this->admin)->post(route('branch.stones.store'), array_merge([
            'name' => 'Lot '.uniqid(),
            'status' => 'in_stock',
        ], $fields))->assertRedirect();

        return Stone::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function pieceLot(): Stone
    {
        return $this->lot(['pricing_mode' => 'per_piece', 'price_per_piece' => '7', 'pieces' => 10]);
    }

    private function caratLot(): Stone
    {
        // 10 ct in stock: a carat lot is measured, not counted.
        return $this->lot(['pricing_mode' => 'per_carat', 'carat' => '10', 'price_per_carat' => '40']);
    }

    private function gramLot(): Stone
    {
        return $this->lot(['pricing_mode' => 'per_gram', 'weight_grams' => '10', 'price_per_gram' => '5']);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Stone $stone, int|float $amount): array
    {
        return $stone->isWeighed()
            ? ['line_type' => 'loose_stone', 'stone_id' => $stone->id, 'quantity' => 1, 'weight_grams' => $amount]
            : ['line_type' => 'loose_stone', 'stone_id' => $stone->id, 'quantity' => (int) $amount];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function ticket(array $lines): array
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
            'lines' => $lines,
        ];
    }

    private function piece(JewelleryItemStatus $status = JewelleryItemStatus::AVAILABLE): JewelleryItem
    {
        return JewelleryItem::factory()->create([
            'branch_id' => $this->admin->branch_id,
            'purity_id' => Purity::query()->where('name', '22K')->value('id'),
            'status' => $status,
        ]);
    }

    public function test_every_kind_of_lot_can_be_deleted_and_its_stock_is_written_off(): void
    {
        foreach ([$this->pieceLot(), $this->caratLot(), $this->gramLot()] as $stone) {
            $this->actingAs($this->admin)->get(route('branch.stones.show', $stone))
                ->assertOk()->assertSee('data-confirm-delete-stone', false);

            $this->actingAs($this->admin)->delete(route('branch.stones.destroy', $stone))
                ->assertRedirect(route('branch.stones.index'));

            $this->assertSoftDeleted('stones', ['id' => $stone->id]);
            $row = StoneStockMovement::query()->where('stone_id', $stone->id)->where('type', StoneMovementType::ADJUSTED)->latest('id')->firstOrFail();
            $this->assertSame($stone->isWeighed() ? '-10.000' : '-10', $stone->isWeighed() ? (string) $row->weight_change : (string) $row->quantity_change);
            $this->actingAs($this->admin)->get(route('branch.stones.index'))->assertDontSee(route('branch.stones.show', $stone));
        }
    }

    public function test_a_lot_with_no_stock_left_deletes_without_a_write_off(): void
    {
        $stone = $this->pieceLot();
        app(\App\Services\StoneStockService::class)->apply($stone, StoneMovementType::ADJUSTED, -10, $this->admin);
        $before = StoneStockMovement::query()->where('stone_id', $stone->id)->count();

        $this->actingAs($this->admin)->delete(route('branch.stones.destroy', $stone))->assertRedirect();

        $this->assertSoftDeleted('stones', ['id' => $stone->id]);
        $this->assertSame($before, StoneStockMovement::query()->where('stone_id', $stone->id)->count());
    }

    public function test_a_lot_already_sold_can_be_deleted_and_the_invoice_still_prints(): void
    {
        $stone = $this->pieceLot();
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), array_merge(
            $this->ticket([$this->line($stone, 3)]),
            ['payment_method' => 'cash']
        ))->assertOk();
        $invoice = Invoice::query()->firstOrFail();

        $this->actingAs($this->admin)->delete(route('branch.stones.destroy', $stone))->assertRedirect(route('branch.stones.index'));
        $this->assertSoftDeleted('stones', ['id' => $stone->id]);

        $this->actingAs($this->admin)->get(route('branch.invoices.show', $invoice))->assertOk();
        $this->actingAs($this->admin)->get(route('branch.invoices.print', $invoice))->assertOk();
    }

    public function test_a_lot_needed_by_an_open_document_cannot_be_deleted_until_it_closes(): void
    {
        $quoted = $this->pieceLot();
        $outOnCredit = $this->gramLot();
        $delivered = $this->caratLot();
        $creditor = Creditor::factory()->create(['branch_id' => $this->admin->branch_id]);

        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket([$this->line($quoted, 2)]))->assertOk();
        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($outOnCredit, 4)],
        ])->assertOk();
        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket([$this->line($delivered, 2)]))->assertOk();

        foreach ([$quoted, $outOnCredit, $delivered] as $stone) {
            $this->actingAs($this->admin)->from(route('branch.stones.show', $stone))
                ->delete(route('branch.stones.destroy', $stone))
                ->assertRedirect(route('branch.stones.show', $stone))
                ->assertSessionHas('error');
            $this->assertNotSoftDeleted('stones', ['id' => $stone->id]);
        }

        // Close each document and the lots can go.
        $this->actingAs($this->admin)->post(route('branch.quotations.cancel', Quotation::query()->firstOrFail()), ['cancel_reason' => 'Client changed their mind'])->assertRedirect();
        $this->actingAs($this->admin)->postJson(route('branch.performas.return', Performa::query()->firstOrFail()), ['return_all' => true])->assertOk();
        $this->actingAs($this->admin)->post(route('branch.delivery-notes.cancel', DeliveryNote::query()->firstOrFail()), ['cancel_reason' => 'Client changed their mind'])->assertRedirect();

        foreach ([$quoted, $outOnCredit, $delivered] as $stone) {
            $this->actingAs($this->admin)->delete(route('branch.stones.destroy', $stone))->assertRedirect(route('branch.stones.index'));
            $this->assertSoftDeleted('stones', ['id' => $stone->id]);
        }
    }

    public function test_only_permitted_users_of_the_same_branch_can_delete_a_lot(): void
    {
        $stone = $this->pieceLot();
        $manager = User::factory()->manager($this->admin->branch_id)->create();
        $stranger = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($manager)->delete(route('branch.stones.destroy', $stone))->assertForbidden();
        $this->actingAs($stranger)->delete(route('branch.stones.destroy', $stone))->assertForbidden();
        $this->assertNotSoftDeleted('stones', ['id' => $stone->id]);
    }

    public function test_a_piece_in_no_document_can_be_deleted_and_returns_its_stones_to_stock(): void
    {
        $stone = $this->pieceLot();
        $category = Category::factory()->create(['name' => 'Rings']);
        $metalId = (int) MetalType::query()->where('name', 'Gold')->value('id');
        $purityId = (int) Purity::query()->where('metal_type_id', $metalId)->where('name', '22K')->value('id');

        $this->actingAs($this->admin)->post(route('branch.jewellery-items.store'), [
            'category_id' => $category->id,
            'name' => 'Ring',
            'metal_type_id' => $metalId,
            'purity_id' => $purityId,
            'gross_weight' => '10.000',
            'metal_weight' => '9.500',
            'status' => 'available',
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 4]],
        ])->assertRedirect();
        $item = JewelleryItem::query()->firstOrFail();
        $this->assertSame(6, (int) $stone->fresh()->quantity);

        $this->actingAs($this->admin)->get(route('branch.jewellery-items.show', $item))->assertOk()->assertSee('data-confirm-delete-item', false);
        $this->actingAs($this->admin)->delete(route('branch.jewellery-items.destroy', $item))->assertRedirect(route('branch.jewellery-items.index'));

        $this->assertSoftDeleted('jewellery_items', ['id' => $item->id]);
        $this->assertSame(10, (int) $stone->fresh()->quantity, 'the stones are back in stock');
        $this->assertSame(1, StoneStockMovement::query()->where('type', StoneMovementType::RETURNED_FROM_ITEM)->count());
    }

    public function test_pieces_that_are_free_or_out_of_service_can_be_deleted_but_occupied_ones_cannot(): void
    {
        foreach ([JewelleryItemStatus::AVAILABLE, JewelleryItemStatus::DAMAGED, JewelleryItemStatus::LOST, JewelleryItemStatus::UNDER_REPAIR] as $status) {
            $item = $this->piece($status);
            $this->actingAs($this->admin)->delete(route('branch.jewellery-items.destroy', $item))->assertRedirect();
            $this->assertSoftDeleted('jewellery_items', ['id' => $item->id]);
        }

        foreach ([JewelleryItemStatus::RESERVED, JewelleryItemStatus::SOLD, JewelleryItemStatus::ON_MEMO, JewelleryItemStatus::DELIVERED, JewelleryItemStatus::TRANSFERRED] as $status) {
            $item = $this->piece($status);
            $this->actingAs($this->admin)->get(route('branch.jewellery-items.show', $item))
                ->assertOk()->assertDontSee('data-confirm-delete-item', false);
            $this->actingAs($this->admin)->delete(route('branch.jewellery-items.destroy', $item))->assertForbidden();
            $this->assertNotSoftDeleted('jewellery_items', ['id' => $item->id]);
        }
    }

    public function test_the_lists_offer_delete_where_it_is_allowed_and_say_n_a_where_it_is_not(): void
    {
        $stone = $this->pieceLot();
        $free = $this->piece();
        $sold = $this->piece(JewelleryItemStatus::SOLD);

        $this->actingAs($this->admin)->get(route('branch.stones.index', ['pricing_mode' => 'per_piece']))
            ->assertOk()
            ->assertSee('form="delete-stone-'.$stone->uuid.'"', false)
            ->assertSee('id="delete-stone-'.$stone->uuid.'"', false);

        $page = $this->actingAs($this->admin)->get(route('branch.jewellery-items.index'))->assertOk();
        $page->assertSee('form="delete-item-'.$free->uuid.'"', false)
            ->assertSee('id="delete-item-'.$free->uuid.'"', false)
            ->assertDontSee('id="delete-item-'.$sold->uuid.'"', false)
            ->assertSee('table-admin__action--locked', false)
            ->assertSee('Sold: this piece is in use and cannot be deleted');

        // Without the permission there is neither a button nor an N/A.
        $cashier = User::factory()->cashier($this->admin->branch_id)->create();
        $this->actingAs($cashier)->get(route('branch.jewellery-items.index'))->assertOk()->assertDontSee('table-admin__action--locked', false)->assertDontSee('delete-item-');
    }

    public function test_a_piece_whose_lot_was_deleted_earlier_can_still_be_deleted(): void
    {
        $stone = $this->pieceLot();
        $metalId = (int) MetalType::query()->where('name', 'Gold')->value('id');
        $this->actingAs($this->admin)->post(route('branch.jewellery-items.store'), [
            'category_id' => Category::factory()->create(['name' => 'Rings'])->id,
            'name' => 'Ring',
            'metal_type_id' => $metalId,
            'purity_id' => (int) Purity::query()->where('metal_type_id', $metalId)->where('name', '22K')->value('id'),
            'gross_weight' => '10.000',
            'metal_weight' => '9.500',
            'status' => 'available',
            'stones' => [['uuid' => $stone->uuid, 'quantity' => 2]],
        ])->assertRedirect();
        $item = JewelleryItem::query()->firstOrFail();

        $this->actingAs($this->admin)->delete(route('branch.stones.destroy', $stone))->assertRedirect(route('branch.stones.index'));
        $this->actingAs($this->admin)->get(route('branch.jewellery-items.show', $item))->assertOk();
        $this->actingAs($this->admin)->delete(route('branch.jewellery-items.destroy', $item))->assertRedirect();
        $this->assertSoftDeleted('jewellery_items', ['id' => $item->id]);
    }
}
