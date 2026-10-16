<?php

namespace Tests\Feature;

use App\Enums\DeliveryNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\SettingGroup;
use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\Purity;
use App\Models\Quotation;
use App\Models\Stone;
use App\Models\StoneStockMovement;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\SettingService;
use App\Services\StoneStockService;
use Database\Seeders\MetalPuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Delivery notes (albarán): issued from the POS, stock out at once, price and
 * discount but no tax, client-only print, cancel and convert to invoice.
 */
class DeliveryNoteTest extends TestCase
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
        $purity = Purity::query()->where('name', '22K')->firstOrFail();

        return [$branch, $admin, $purity];
    }

    private function piece(User $admin, Purity $purity): JewelleryItem
    {
        return JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);
    }

    private function stockedStone(User $admin, int $quantity = 5): Stone
    {
        $stone = Stone::factory()->create(['branch_id' => $admin->branch_id, 'is_active' => true]);
        app(StoneStockService::class)->apply($stone, StoneMovementType::INTAKE, $quantity, $admin);

        return $stone->fresh();
    }

    /**
     * Workspace payload with one jewellery line.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(JewelleryItem $item, array $overrides = []): array
    {
        return array_merge([
            'walkin_name' => 'Maria Garcia',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'discount_value' => 0,
            'tax_type' => 'none',
            'tax_rate' => 0,
            'apply_tax' => false,
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
     * @return array<string, mixed>
     */
    private function stonePayload(Stone $stone, int $quantity, array $overrides = []): array
    {
        return array_merge([
            'walkin_name' => 'Maria Garcia',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'discount_value' => 0,
            'tax_type' => 'none',
            'tax_rate' => 0,
            'lines' => [[
                'line_type' => LineType::LOOSE_STONE->value,
                'stone_id' => $stone->id,
                'quantity' => $quantity,
                'price_per_piece' => 20,
            ]],
        ], $overrides);
    }

    private function enableTax(): void
    {
        $this->useTax(true, 18);
    }

    public function test_issuing_takes_loose_stone_out_and_uses_the_alb_series(): void
    {
        [, $admin] = $this->branchAdmin();
        $stone = $this->stockedStone($admin, 5);

        $response = $this->actingAs($admin)
            ->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 3))
            ->assertOk()
            ->assertJsonPath('success', true);

        $note = DeliveryNote::query()->firstOrFail();
        $this->assertSame($note->delivery_note_number, $response->json('data.number'));
        $this->assertStringStartsWith('ALB-', $note->delivery_note_number);
        $this->assertSame(DeliveryNoteStatus::ISSUED, $note->status);
        $this->assertSame(route('branch.delivery-notes.print', $note), $response->json('data.print_url'));

        $this->assertSame(2, (int) $stone->fresh()->quantity);
        $this->assertEqualsWithDelta(60.0, (float) $note->grand_total, 0.001);

        $movement = StoneStockMovement::query()->where('type', StoneMovementType::DELIVERED)->firstOrFail();
        $this->assertSame(-3, (int) $movement->quantity_change);

        // Not a quotation and not an invoice: nothing else was created.
        $this->assertSame(0, Quotation::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_issuing_marks_jewellery_delivered(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $item = $this->piece($admin, $purity);

        $this->actingAs($admin)
            ->postJson(route('branch.delivery-notes.store'), $this->payload($item))
            ->assertOk();

        $this->assertSame(JewelleryItemStatus::DELIVERED, $item->fresh()->status);

        // A delivered piece cannot go on a second document.
        $this->actingAs($admin)
            ->postJson(route('branch.delivery-notes.store'), $this->payload($item, ['walkin_phone' => '03007654321']))
            ->assertStatus(422);
        $this->assertSame(1, DeliveryNote::query()->count());
    }

    public function test_no_tax_even_when_tax_is_enabled_but_discount_applies(): void
    {
        [, $admin] = $this->branchAdmin();
        $this->enableTax();
        app(SettingService::class)->setGroup(SettingGroup::DISCOUNT, [
            'discount_enabled' => true,
            'max_discount_percentage' => 50,
        ], User::factory()->create());
        $stone = $this->stockedStone($admin, 5);

        $this->actingAs($admin)
            ->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 5, [
                'apply_tax' => true,
                'tax_type' => 'percentage',
                'tax_rate' => 18,
                'discount_type' => 'percentage',
                'discount_value' => 10,
            ]))
            ->assertOk();

        $note = DeliveryNote::query()->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $note->subtotal, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $note->discount_amount, 0.001);
        // 100 - 10% = 90, with no GST on top.
        $this->assertEqualsWithDelta(90.0, (float) $note->grand_total, 0.001);
        $this->assertFalse(Schema::hasColumn('delivery_notes', 'tax_amount'));
    }

    public function test_numbers_are_a_separate_series_and_the_preview_does_not_take_one(): void
    {
        [, $admin] = $this->branchAdmin();
        $stone = $this->stockedStone($admin, 5);

        $shown = $this->actingAs($admin)->getJson(route('branch.pos.numbers'))->json('data');
        $this->assertStringStartsWith('ALB-', $shown['delivery_note']);
        $this->assertSame($shown['delivery_note'], $this->actingAs($admin)->getJson(route('branch.pos.numbers'))->json('data.delivery_note'));

        $this->actingAs($admin)
            ->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 1))
            ->assertOk()
            ->assertJsonPath('data.number', $shown['delivery_note']);

        $after = $this->actingAs($admin)->getJson(route('branch.pos.numbers'))->json('data');
        $this->assertNotSame($shown['delivery_note'], $after['delivery_note']);
        $this->assertSame($shown['invoice'], $after['invoice']);
        $this->assertSame($shown['quotation'], $after['quotation']);
    }

    public function test_the_print_page_shows_only_the_client(): void
    {
        [$branch, $admin] = $this->branchAdmin();
        $stone = $this->stockedStone($admin, 5);

        $this->actingAs($admin)
            ->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 2))
            ->assertOk();
        $note = DeliveryNote::query()->firstOrFail();

        $html = $this->actingAs($admin)->get(route('branch.delivery-notes.print', $note))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Delivery Note', $html);
        $this->assertStringNotContainsString('Albarán', $html);
        $this->assertStringContainsString($note->delivery_note_number, $html);
        $this->assertStringContainsString('Maria Garcia', $html);
        $this->assertStringContainsString('03001234567', $html);

        // Nothing about the shop.
        $company = (string) setting('company_name', config('annigems.documents.brand_name'));
        if ($company !== '') {
            $this->assertStringNotContainsString($company, $html);
        }
        $this->assertStringNotContainsString($branch->name, $html);
        $this->assertStringNotContainsString('sheet__brand', $html);
        $this->assertStringNotContainsString('sheet__qr', $html);
        $this->assertStringNotContainsString('Terms', $html);
    }

    public function test_cancel_returns_the_goods(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $stone = $this->stockedStone($admin, 5);
        $item = $this->piece($admin, $purity);

        $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 2))->assertOk();
        $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->payload($item, ['walkin_phone' => '03007654321']))->assertOk();

        $stoneNote = DeliveryNote::query()->orderBy('id')->firstOrFail();
        $itemNote = DeliveryNote::query()->orderByDesc('id')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('branch.delivery-notes.cancel', $stoneNote), ['cancel_reason' => 'Client changed their mind'])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('branch.delivery-notes.cancel', $itemNote), ['cancel_reason' => 'Client changed their mind'])
            ->assertRedirect();

        $this->assertSame(5, (int) $stone->fresh()->quantity);
        $this->assertSame(JewelleryItemStatus::AVAILABLE, $item->fresh()->status);
        $this->assertSame(DeliveryNoteStatus::CANCELLED, $stoneNote->fresh()->status);
        $this->assertSame(1, StoneStockMovement::query()->where('type', StoneMovementType::RETURNED_FROM_DELIVERY)->count());

        // Cancelling twice is refused.
        $this->actingAs($admin)
            ->post(route('branch.delivery-notes.cancel', $stoneNote), ['cancel_reason' => 'Client changed their mind'])
            ->assertForbidden();
        $this->assertSame(5, (int) $stone->fresh()->quantity);
    }

    public function test_convert_applies_the_iva_and_recargo_chosen_at_conversion(): void
    {
        [, $admin] = $this->branchAdmin();
        $this->enableTax();
        $recargo = TaxRate::query()->create(['kind' => 'recargo', 'name' => 'Recargo 5.2%', 'rate' => 5.2, 'is_active' => true]);
        $iva = TaxRate::query()->where('kind', 'iva')->firstOrFail();
        $stone = $this->stockedStone($admin, 5);

        foreach ([2, 2] as $qty) {
            $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, $qty))->assertOk();
        }
        [$first, $second] = DeliveryNote::query()->orderBy('id')->get()->all();

        // IVA 18% + recargo 5.2% on the 40.00 subtotal.
        $this->actingAs($admin)
            ->post(route('branch.delivery-notes.convert', $first), [
                'payment_method' => 'cash',
                'tax_rate_uuid' => $iva->uuid,
                'recargo_rate_uuid' => $recargo->uuid,
            ])->assertRedirect();
        $invoice = $first->fresh()->invoice;
        $this->assertEqualsWithDelta(7.2, (float) $invoice->tax_amount, 0.001);
        $this->assertEqualsWithDelta(2.08, (float) $invoice->recargo_amount, 0.001);
        $this->assertSame('Recargo 5.2%', $invoice->recargo_name);
        $this->assertEqualsWithDelta(49.28, (float) $invoice->grand_total, 0.001);

        // A blank IVA means none at all.
        $this->actingAs($admin)
            ->post(route('branch.delivery-notes.convert', $second), ['payment_method' => 'cash', 'tax_rate_uuid' => ''])
            ->assertRedirect();
        $plain = $second->fresh()->invoice;
        $this->assertEqualsWithDelta(0.0, (float) $plain->tax_amount, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $plain->grand_total, 0.001);

        // The convert modal offers the rates.
        $third = $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 1))->assertOk();
        $this->actingAs($admin)
            ->get(route('branch.delivery-notes.show', DeliveryNote::query()->orderByDesc('id')->firstOrFail()))
            ->assertOk()
            ->assertSee('name="tax_rate_uuid"', false)
            ->assertSee('name="recargo_rate_uuid"', false)
            ->assertSee('Recargo 5.2%');
    }

    public function test_convert_makes_an_invoice_with_tax_and_cuts_nothing_twice(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $this->enableTax();
        $stone = $this->stockedStone($admin, 5);
        $item = $this->piece($admin, $purity);

        $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 2))->assertOk();
        $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->payload($item, ['walkin_phone' => '03007654321']))->assertOk();
        $this->assertSame(3, (int) $stone->fresh()->quantity);

        $stoneNote = DeliveryNote::query()->orderBy('id')->firstOrFail();
        $itemNote = DeliveryNote::query()->orderByDesc('id')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('branch.delivery-notes.convert', $stoneNote), [
                'payment_method' => 'cash',
                'tax_rate_uuid' => TaxRate::query()->where('kind', 'iva')->value('uuid'),
            ])
            ->assertRedirect();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame($stoneNote->id, $invoice->delivery_note_id);
        $this->assertSame(InvoiceStatus::COMPLETED, $invoice->status);
        $this->assertEqualsWithDelta(40.0, (float) $invoice->subtotal, 0.001);
        // The note had no tax; the invoice adds 18% GST.
        $this->assertEqualsWithDelta(7.2, (float) $invoice->tax_amount, 0.001);
        $this->assertEqualsWithDelta(47.2, (float) $invoice->grand_total, 0.001);
        $this->assertSame(1, $invoice->items()->count());

        $this->assertSame(3, (int) $stone->fresh()->quantity, 'stock must not be cut a second time');
        $this->assertSame(DeliveryNoteStatus::INVOICED, $stoneNote->fresh()->status);
        $this->assertSame($invoice->id, $stoneNote->fresh()->invoice_id);
        $this->assertSame(1, StoneStockMovement::query()->where('type', StoneMovementType::SOLD)->count());

        $this->actingAs($admin)
            ->post(route('branch.delivery-notes.convert', $itemNote), ['payment_method' => 'cash'])
            ->assertRedirect();
        $this->assertSame(JewelleryItemStatus::SOLD, $item->fresh()->status);

        // An invoiced note can be neither cancelled nor converted again.
        $this->actingAs($admin)
            ->post(route('branch.delivery-notes.cancel', $stoneNote), ['cancel_reason' => 'Client changed their mind'])
            ->assertForbidden();
        $this->actingAs($admin)
            ->post(route('branch.delivery-notes.convert', $stoneNote), ['payment_method' => 'cash'])
            ->assertForbidden();
    }

    public function test_voiding_the_invoice_reopens_the_note_and_keeps_goods_out(): void
    {
        [, $admin, $purity] = $this->branchAdmin();
        $stone = $this->stockedStone($admin, 5);
        $item = $this->piece($admin, $purity);

        $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 2))->assertOk();
        $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->payload($item, ['walkin_phone' => '03007654321']))->assertOk();
        $notes = DeliveryNote::query()->orderBy('id')->get();

        foreach ($notes as $note) {
            $this->actingAs($admin)->post(route('branch.delivery-notes.convert', $note), ['payment_method' => 'cash'])->assertRedirect();
        }

        foreach (Invoice::query()->get() as $invoice) {
            $this->actingAs($admin)
                ->post(route('branch.invoices.void', $invoice), ['void_reason' => 'Entered by mistake today'])
                ->assertRedirect();
        }

        // The goods are still with the clients, so shelf stock is unchanged.
        $this->assertSame(3, (int) $stone->fresh()->quantity);
        $this->assertSame(JewelleryItemStatus::DELIVERED, $item->fresh()->status);

        foreach ($notes as $note) {
            $this->assertSame(DeliveryNoteStatus::ISSUED, $note->fresh()->status);
            $this->assertNull($note->fresh()->invoice_id);
        }
    }

    public function test_cashier_can_issue_and_convert_but_not_cancel(): void
    {
        [$branch, $admin] = $this->branchAdmin();
        $cashier = User::factory()->cashier($branch->id)->create();
        $stone = $this->stockedStone($admin, 5);

        $this->actingAs($cashier)
            ->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 1))
            ->assertOk();
        $note = DeliveryNote::query()->firstOrFail();

        $this->actingAs($cashier)->get(route('branch.delivery-notes.show', $note))->assertOk();
        $this->actingAs($cashier)
            ->post(route('branch.delivery-notes.cancel', $note), ['cancel_reason' => 'Client changed their mind'])
            ->assertForbidden();
    }

    public function test_other_branch_and_superadmin_are_refused(): void
    {
        [, $admin] = $this->branchAdmin();
        $stone = $this->stockedStone($admin, 5);
        $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->stonePayload($stone, 1))->assertOk();
        $note = DeliveryNote::query()->firstOrFail();

        $otherBranch = Branch::factory()->create();
        $other = User::factory()->admin($otherBranch->id)->create();
        $this->actingAs($other)->get(route('branch.delivery-notes.show', $note))->assertForbidden();
        $this->actingAs($other)->get(route('branch.delivery-notes.print', $note))->assertForbidden();

        $superadmin = User::factory()->create();
        $this->actingAs($superadmin)->get(route('branch.delivery-notes.show', $note))->assertForbidden();
    }

    public function test_pos_offers_the_delivery_note_option_and_the_list_page_loads(): void
    {
        [, $admin] = $this->branchAdmin();

        $this->actingAs($admin)->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('data-kind="delivery_note"', false)
            ->assertSee('data-generate-delivery-note', false);

        $this->actingAs($admin)->get(route('branch.delivery-notes.index'))->assertOk();
    }
}
