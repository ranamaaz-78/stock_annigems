<?php

namespace Tests\Feature;

use App\Enums\DeliveryNoteStatus;
use App\Enums\PerformaStatus;
use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Creditor;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Performa;
use App\Models\Quotation;
use App\Models\Stone;
use App\Models\StoneStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three ways a stone lot is sold — by the piece, by the carat (a piece
 * priced carat x rate) and by the gram — through every sales document.
 *
 * A per-gram lot is stocked in grams, so the POS must ask for grams and every
 * stock movement must be in grams. Piece and carat lots keep working beside it.
 */
class StoneUnitsTest extends TestCase
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
     * Register a lot through the stone form.
     *
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
        // 0.5 ct x 40 per carat = 20 per piece.
        return $this->lot(['pricing_mode' => 'per_carat', 'carat' => '0.500', 'price_per_carat' => '40', 'pieces' => 10]);
    }

    private function gramLot(string $grams = '10'): Stone
    {
        return $this->lot(['pricing_mode' => 'per_gram', 'weight_grams' => $grams, 'price_per_gram' => '5']);
    }

    /**
     * One loose-stone line.
     *
     * @return array<string, mixed>
     */
    private function line(Stone $stone, int|float $amount, ?float $price = null): array
    {
        $line = ['line_type' => 'loose_stone', 'stone_id' => $stone->id];

        if ($stone->isWeighed()) {
            $line['quantity'] = 1;
            $line['weight_grams'] = $amount;
        } else {
            $line['quantity'] = (int) $amount;
        }

        if ($price !== null) {
            $line['price_per_piece'] = $price;
        }

        return $line;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ticket(array $lines, array $overrides = []): array
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
            'lines' => $lines,
        ], $overrides);
    }

    private function onHand(Stone $stone): string
    {
        $fresh = $stone->fresh();

        return $fresh->isWeighed()
            ? number_format((float) $fresh->weight_grams, 3, '.', '')
            : (string) $fresh->quantity;
    }

    private function ledger(Stone $stone, StoneMovementType $type): StoneStockMovement
    {
        return StoneStockMovement::query()->where('stone_id', $stone->id)->where('type', $type)->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ lookup

    public function test_scanning_each_kind_of_lot_works_and_a_gram_lot_asks_for_grams(): void
    {
        $piece = $this->pieceLot();
        $carat = $this->caratLot();
        $gram = $this->gramLot('303');

        foreach ([$piece, $carat, $gram] as $stone) {
            $this->actingAs($this->admin)
                ->postJson(route('branch.quotations.lookup'), ['code' => $stone->stone_code])
                ->assertOk()
                ->assertJsonPath('data.line.stone_id', $stone->id);
        }

        $pieceLine = $this->actingAs($this->admin)->postJson(route('branch.quotations.lookup'), ['code' => $piece->stone_code])->json('data.line');
        $this->assertFalse($pieceLine['weighed']);
        $this->assertSame('7.00', $pieceLine['price_per_piece']);

        $caratLine = $this->actingAs($this->admin)->postJson(route('branch.quotations.lookup'), ['code' => $carat->stone_code])->json('data.line');
        $this->assertFalse($caratLine['weighed']);
        $this->assertSame('20.00', $caratLine['price_per_piece']);

        $gramLine = $this->actingAs($this->admin)->postJson(route('branch.quotations.lookup'), ['code' => $gram->stone_code])->json('data.line');
        $this->assertTrue($gramLine['weighed']);
        $this->assertSame('303.000', $gramLine['available_grams']);
        $this->assertSame('', $gramLine['weight_grams']);
        // The price field is the price of one gram.
        $this->assertSame('5.00', $gramLine['price_per_piece']);
    }

    // ----------------------------------------------------------- quotations

    public function test_quotation_reserves_grams_cancel_returns_them_and_convert_sells_them(): void
    {
        $gram = $this->gramLot('10');

        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $this->ticket([$this->line($gram, 5.5, 5)]))
            ->assertOk();

        $quotation = Quotation::query()->latest('id')->firstOrFail();
        $line = $quotation->items()->firstOrFail();
        $this->assertSame('5.500', (string) $line->weight_grams);
        $this->assertSame('27.50', (string) $line->line_total);
        $this->assertSame('27.50', (string) $quotation->grand_total);
        $this->assertSame('4.500', $this->onHand($gram));
        $this->assertSame('-5.500', (string) $this->ledger($gram, StoneMovementType::RESERVED_ON_QUOTATION)->weight_change);

        // Cancel: the grams come back.
        $this->actingAs($this->admin)->post(route('branch.quotations.cancel', $quotation))->assertRedirect();
        $this->assertSame('10.000', $this->onHand($gram));
        $this->assertSame('5.500', (string) $this->ledger($gram, StoneMovementType::RELEASED_FROM_QUOTATION)->weight_change);

        // A new quotation, converted: held grams become a sale and nothing is cut twice.
        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $this->ticket([$this->line($gram, 3, 5)], ['walkin_phone' => '03007654321']))
            ->assertOk();
        $second = Quotation::query()->latest('id')->firstOrFail();
        $this->assertSame('7.000', $this->onHand($gram));

        $this->actingAs($this->admin)->post(route('branch.quotations.convert', $second), ['payment_method' => 'cash'])->assertRedirect();
        $this->assertSame('7.000', $this->onHand($gram), 'converting must not cut the grams a second time');
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->assertSame('3.000', (string) $invoice->items()->firstOrFail()->weight_grams);
        $this->assertSame('15.00', (string) $invoice->grand_total);
        $this->assertTrue(StoneStockMovement::query()->where('stone_id', $gram->id)->where('type', StoneMovementType::SOLD)->exists());
    }

    public function test_editing_a_gram_quotation_moves_only_the_difference(): void
    {
        $gram = $this->gramLot('10');
        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket([$this->line($gram, 4, 5)]))->assertOk();
        $quotation = Quotation::query()->latest('id')->firstOrFail();
        $this->assertSame('6.000', $this->onHand($gram));

        $this->actingAs($this->admin)
            ->putJson(route('branch.quotations.update', $quotation), $this->ticket([$this->line($gram, 7, 5)]))
            ->assertOk();

        $this->assertSame('3.000', $this->onHand($gram));
        $this->assertSame('7.000', (string) $quotation->fresh()->items()->firstOrFail()->weight_grams);
        $this->assertSame('35.00', (string) $quotation->fresh()->grand_total);
    }

    // ------------------------------------------------------ direct invoice

    public function test_direct_invoice_for_each_kind_cuts_the_right_unit_and_void_returns_it(): void
    {
        $piece = $this->pieceLot();
        $carat = $this->caratLot();
        $gram = $this->gramLot('10');

        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket([
            $this->line($piece, 3, 7),
            $this->line($carat, 2, 20),
            $this->line($gram, 5.5, 5),
        ], ['payment_method' => 'cash']))->assertOk();

        $invoice = Invoice::query()->latest('id')->firstOrFail();
        // 3 x 7 + 2 x 20 + 5.5 x 5
        $this->assertSame('88.50', (string) $invoice->grand_total);
        $this->assertSame('7', $this->onHand($piece));
        $this->assertSame('8', $this->onHand($carat));
        $this->assertSame('4.500', $this->onHand($gram));
        $this->assertSame('-5.500', (string) $this->ledger($gram, StoneMovementType::SOLD)->weight_change);

        $this->actingAs($this->admin)
            ->post(route('branch.invoices.void', $invoice), ['void_reason' => 'Entered by mistake today'])
            ->assertRedirect();

        $this->assertSame('10', $this->onHand($piece));
        $this->assertSame('10', $this->onHand($carat));
        $this->assertSame('10.000', $this->onHand($gram));
        $this->assertSame('5.500', (string) $this->ledger($gram, StoneMovementType::RETURNED_FROM_SALE)->weight_change);
    }

    public function test_discount_and_tax_apply_on_the_gram_total(): void
    {
        $this->useTax(true, 10);
        app(\App\Services\SettingService::class)->setGroup(\App\Enums\SettingGroup::DISCOUNT, [
            'discount_enabled' => true,
            'max_discount_percentage' => 50,
        ], User::factory()->create());
        $gram = $this->gramLot('10');

        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket(
            [$this->line($gram, 4, 5)],
            ['discount_type' => 'percentage', 'discount_value' => 10, 'apply_tax' => true]
        ))->assertOk();

        $quotation = Quotation::query()->latest('id')->firstOrFail();
        // 4 g x 5 = 20, less 10% = 18, plus 10% tax = 19.80
        $this->assertSame('20.00', (string) $quotation->subtotal);
        $this->assertSame('18.00', (string) bcsub((string) $quotation->subtotal, (string) $quotation->discount_amount, 2));
        $this->assertSame('1.80', (string) $quotation->tax_amount);
        $this->assertSame('19.80', (string) $quotation->grand_total);
    }

    // ----------------------------------------------------------- refusals

    public function test_grams_are_required_checked_against_stock_and_shared_between_lines(): void
    {
        $gram = $this->gramLot('10');
        $piece = $this->pieceLot();

        // No grams.
        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $this->ticket([['line_type' => 'loose_stone', 'stone_id' => $gram->id, 'quantity' => 1, 'price_per_piece' => 5]]))
            ->assertStatus(422);

        // Zero grams.
        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $this->ticket([$this->line($gram, 0, 5)]))
            ->assertStatus(422);

        // More than is on hand.
        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $this->ticket([$this->line($gram, 10.5, 5)]))
            ->assertStatus(422);

        // Two lines of the same lot cannot together take more than the lot holds.
        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.invoice'), $this->ticket([$this->line($gram, 6, 5), $this->line($gram, 6, 5)], ['payment_method' => 'cash']))
            ->assertStatus(422);

        // Grams sent for a piece lot are ignored, not trusted.
        $this->actingAs($this->admin)
            ->postJson(route('branch.quotations.store'), $this->ticket([['line_type' => 'loose_stone', 'stone_id' => $piece->id, 'quantity' => 2, 'weight_grams' => 99, 'price_per_piece' => 7]]))
            ->assertOk();
        $this->assertNull(Quotation::query()->latest('id')->firstOrFail()->items()->firstOrFail()->weight_grams);

        $this->assertSame('10.000', $this->onHand($gram), 'refused tickets must not move stock');
        $this->assertSame(0, Invoice::query()->count());
    }

    // ------------------------------------------------------- delivery notes

    public function test_delivery_note_takes_grams_out_and_cancel_returns_them(): void
    {
        $piece = $this->pieceLot();
        $gram = $this->gramLot('10');

        $this->actingAs($this->admin)
            ->postJson(route('branch.delivery-notes.store'), $this->ticket([$this->line($piece, 2, 7), $this->line($gram, 4.25, 5)]))
            ->assertOk();

        $note = DeliveryNote::query()->latest('id')->firstOrFail();
        $this->assertSame('35.25', (string) $note->grand_total);
        $this->assertSame('8', $this->onHand($piece));
        $this->assertSame('5.750', $this->onHand($gram));
        $this->assertSame('-4.250', (string) $this->ledger($gram, StoneMovementType::DELIVERED)->weight_change);

        $html = $this->actingAs($this->admin)->get(route('branch.delivery-notes.print', $note))->assertOk()->getContent();
        $this->assertStringContainsString('4.25 g', $html);

        $this->actingAs($this->admin)->post(route('branch.delivery-notes.cancel', $note), ['cancel_reason' => 'Client changed their mind'])->assertRedirect();
        $this->assertSame('10', $this->onHand($piece));
        $this->assertSame('10.000', $this->onHand($gram));
        $this->assertSame('4.250', (string) $this->ledger($gram, StoneMovementType::RETURNED_FROM_DELIVERY)->weight_change);
    }

    public function test_delivery_note_converts_to_an_invoice_without_cutting_grams_again_and_void_reopens_it(): void
    {
        $gram = $this->gramLot('10');
        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket([$this->line($gram, 6, 5)]))->assertOk();
        $note = DeliveryNote::query()->latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post(route('branch.delivery-notes.convert', $note), ['payment_method' => 'cash'])->assertRedirect();
        $invoice = Invoice::query()->latest('id')->firstOrFail();

        $this->assertSame('4.000', $this->onHand($gram));
        $this->assertSame('6.000', (string) $invoice->items()->firstOrFail()->weight_grams);
        $this->assertSame('30.00', (string) $invoice->grand_total);
        $this->assertSame(DeliveryNoteStatus::INVOICED, $note->fresh()->status);

        $this->actingAs($this->admin)->post(route('branch.invoices.void', $invoice), ['void_reason' => 'Entered by mistake today'])->assertRedirect();
        $this->assertSame('4.000', $this->onHand($gram), 'the goods are still with the client');
        $this->assertSame(DeliveryNoteStatus::ISSUED, $note->fresh()->status);
    }

    // ---------------------------------------------------------- performas

    private function creditor(): Creditor
    {
        return Creditor::factory()->create(['branch_id' => $this->admin->branch_id]);
    }

    public function test_performa_issues_grams_then_settles_and_returns_part_of_them(): void
    {
        $piece = $this->pieceLot();
        $gram = $this->gramLot('10');
        $creditor = $this->creditor();

        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($piece, 4), $this->line($gram, 5)],
        ])->assertOk();

        $performa = Performa::query()->latest('id')->firstOrFail();
        $this->assertSame('6', $this->onHand($piece));
        $this->assertSame('5.000', $this->onHand($gram));
        $this->assertSame('-5.000', (string) $this->ledger($gram, StoneMovementType::ISSUED_ON_CREDIT)->weight_change);

        $gramItem = $performa->items()->where('stone_id', $gram->id)->firstOrFail();
        $pieceItem = $performa->items()->where('stone_id', $piece->id)->firstOrFail();
        $this->assertTrue($gramItem->isWeighed());
        $this->assertSame('5.000', (string) $gramItem->weight_issued);
        $this->assertSame(5.0, (float) $gramItem->outstandingAmount());

        // Pay for 3 g of the 5 (and 1 piece): the invoice carries 3 g at 5 per gram.
        $this->actingAs($this->admin)->postJson(route('branch.performas.settle.store', $performa), [
            'payment_method' => 'cash',
            'lines' => [
                ['performa_item_id' => $gramItem->id, 'quantity' => 3, 'price' => '5.00'],
                ['performa_item_id' => $pieceItem->id, 'quantity' => 1, 'price' => '7.00'],
            ],
        ])->assertOk();

        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $invoiceGrams = $invoice->items()->where('stone_id', $gram->id)->firstOrFail();
        $this->assertSame('3.000', (string) $invoiceGrams->weight_grams);
        $this->assertSame('15.00', (string) $invoiceGrams->line_total);
        $this->assertSame('22.00', (string) $invoice->grand_total);
        $this->assertSame('5.000', $this->onHand($gram), 'settling takes nothing more off the shelf');
        $this->assertSame(PerformaStatus::PARTIAL, $performa->fresh()->status);
        $this->assertSame(2.0, (float) $gramItem->fresh()->outstandingAmount());

        // More grams than are left is refused.
        $this->actingAs($this->admin)->postJson(route('branch.performas.settle.store', $performa), [
            'payment_method' => 'cash',
            'lines' => [['performa_item_id' => $gramItem->id, 'quantity' => 2.5, 'price' => '5.00']],
        ])->assertStatus(422);

        // Pieces are settled whole.
        $this->actingAs($this->admin)->postJson(route('branch.performas.settle.store', $performa), [
            'payment_method' => 'cash',
            'lines' => [['performa_item_id' => $pieceItem->id, 'quantity' => 1.5, 'price' => '7.00']],
        ])->assertStatus(422);

        // Give back the remaining 2 g and the 3 pieces.
        $this->actingAs($this->admin)->postJson(route('branch.performas.return', $performa), [
            'lines' => [
                ['performa_item_id' => $gramItem->id, 'quantity' => 2],
                ['performa_item_id' => $pieceItem->id, 'quantity' => 3],
            ],
        ])->assertOk();

        $this->assertSame('7.000', $this->onHand($gram));
        $this->assertSame('9', $this->onHand($piece));
        $this->assertSame('2.000', (string) $this->ledger($gram, StoneMovementType::RETURNED_FROM_CREDIT)->weight_change);
        $this->assertSame(PerformaStatus::SETTLED, $performa->fresh()->status);
        $this->assertSame(0.0, (float) $gramItem->fresh()->outstandingAmount());
    }

    public function test_a_settlement_takes_the_iva_and_recargo_the_cashier_chose(): void
    {
        $this->useTax(true, 21);
        $iva = \App\Models\TaxRate::query()->where('kind', 'iva')->firstOrFail();
        $recargo = \App\Models\TaxRate::query()->create(['kind' => 'recargo', 'name' => 'Recargo 5.2%', 'rate' => 5.2, 'is_active' => true]);
        $piece = $this->pieceLot();
        $creditor = $this->creditor();

        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($piece, 8, 10)],
        ])->assertOk();
        $performa = Performa::query()->latest('id')->firstOrFail();
        $item = $performa->items()->firstOrFail();
        $pay = fn (array $extra) => $this->actingAs($this->admin)->postJson(route('branch.performas.settle.store', $performa), array_merge([
            'payment_method' => 'cash',
            'lines' => [['performa_item_id' => $item->id, 'quantity' => 2, 'price_per_piece' => 10]],
        ], $extra))->assertOk();

        // 20.00 + IVA 21% (4.20) + recargo 5.2% (1.04).
        $pay(['tax_rate_uuid' => $iva->uuid, 'recargo_rate_uuid' => $recargo->uuid]);
        $first = Invoice::query()->latest('id')->firstOrFail();
        $this->assertSame('4.20', (string) $first->tax_amount);
        $this->assertSame('1.04', (string) $first->recargo_amount);
        $this->assertSame('Recargo 5.2%', $first->recargo_name);
        $this->assertSame('25.24', (string) $first->grand_total);

        // A blank IVA means no tax at all.
        $pay(['tax_rate_uuid' => '']);
        $second = Invoice::query()->latest('id')->firstOrFail();
        $this->assertSame('0.00', (string) $second->tax_amount);
        $this->assertSame('20.00', (string) $second->grand_total);

        // No choice sent keeps the default IVA.
        $pay([]);
        $third = Invoice::query()->latest('id')->firstOrFail();
        $this->assertSame('24.20', (string) $third->grand_total);

        // The settle page offers both lists.
        $this->actingAs($this->admin)->get(route('branch.performas.settle', $performa))
            ->assertOk()->assertSee('data-settle-iva', false)->assertSee('data-settle-recargo', false)->assertSee('Recargo 5.2%');
    }

    public function test_the_settle_page_starts_each_line_at_its_issued_price(): void
    {
        $piece = $this->pieceLot();
        $gram = $this->gramLot('10');
        $creditor = $this->creditor();

        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($piece, 2, 9), $this->line($gram, 4, 6.5)],
        ])->assertOk();
        $performa = Performa::query()->latest('id')->firstOrFail();

        $lines = collect(app(\App\Services\PerformaService::class)->outstandingPayload($performa))->keyBy('item_code_snapshot');
        $this->assertSame('9.00', $lines[$piece->stone_code]['price_per_piece']);
        $this->assertSame('6.50', $lines[$gram->stone_code]['price_per_piece']);
    }

    public function test_an_issued_performa_loads_into_the_pos_and_its_edit_moves_only_the_difference(): void
    {
        $piece = $this->pieceLot();
        $carat = $this->caratLot();
        $gram = $this->gramLot('10');
        $creditor = $this->creditor();

        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($piece, 4), $this->line($carat, 3), $this->line($gram, 5)],
        ])->assertOk();
        $performa = Performa::query()->latest('id')->firstOrFail();

        // The edit link opens the POS with this performa, its lines and their own stock as available.
        $this->actingAs($this->admin)->get(route('branch.performas.edit', $performa))
            ->assertRedirect(route('branch.pos.show', ['performa' => $performa->uuid]));
        $page = $this->actingAs($this->admin)->get(route('branch.pos.show', ['performa' => $performa->uuid]))->assertOk();
        $config = json_decode(html_entity_decode($page->viewData('editingPerforma') ? json_encode($page->viewData('editingPerforma')) : '{}'), true);
        $this->assertSame($performa->performa_number, $config['number']);
        $this->assertCount(3, $config['lines']);
        $this->assertFalse($config['creditor_locked']);
        $byCode = collect($config['lines'])->keyBy('item_code_snapshot');
        $this->assertSame(4, $byCode[$piece->stone_code]['quantity']);
        $this->assertSame(10, $byCode[$piece->stone_code]['available_quantity']);
        $this->assertSame('5.000', $byCode[$gram->stone_code]['weight_grams']);
        $this->assertSame('10.000', $byCode[$gram->stone_code]['available_grams']);

        // Change it: pieces 4 -> 6, carat lot dropped, grams 5 -> 8.
        $this->actingAs($this->admin)->putJson(route('branch.performas.update', $performa), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'notes' => 'Edited',
            'lines' => [$this->line($piece, 6), $this->line($gram, 8)],
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame('4', $this->onHand($piece));
        $this->assertSame('10', $this->onHand($carat), 'a dropped line goes back on the shelf');
        $this->assertSame('2.000', $this->onHand($gram));
        $performa->refresh();
        $this->assertSame('Edited', $performa->notes);
        $this->assertSame(2, $performa->items()->count());
        $this->assertSame(8.0, (float) $performa->items()->where('stone_id', $gram->id)->first()->weight_issued);
        $this->assertSame(PerformaStatus::OPEN, $performa->status);

        // More than the shelf plus the line's own grams is refused and nothing moves.
        $this->actingAs($this->admin)->putJson(route('branch.performas.update', $performa), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($piece, 6), $this->line($gram, 10.5)],
        ])->assertStatus(422);
        $this->assertSame('2.000', $this->onHand($gram));
        $this->assertSame('4', $this->onHand($piece));
        $this->assertSame(2, $performa->items()->count());
    }

    public function test_a_performa_with_a_payment_keeps_the_paid_line_and_its_creditor(): void
    {
        $piece = $this->pieceLot();
        $gram = $this->gramLot('10');
        $creditor = $this->creditor();
        $other = $this->creditor();

        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($piece, 4), $this->line($gram, 5)],
        ])->assertOk();
        $performa = Performa::query()->latest('id')->firstOrFail();
        $gramItem = $performa->items()->where('stone_id', $gram->id)->firstOrFail();

        $this->actingAs($this->admin)->postJson(route('branch.performas.settle.store', $performa), [
            'payment_method' => 'cash',
            'lines' => [['performa_item_id' => $gramItem->id, 'quantity' => 3, 'price' => '5.00']],
        ])->assertOk();

        $payload = app(\App\Services\PerformaService::class)->editPayload($performa);
        $this->assertTrue($payload['creditor_locked']);
        $this->assertCount(1, $payload['locked']);
        $this->assertCount(1, $payload['lines'], 'only the unpaid piece line can change');

        // Pieces 4 -> 2, and an attempt to move the performa to another creditor.
        $this->actingAs($this->admin)->putJson(route('branch.performas.update', $performa), [
            'creditor_uuid' => $other->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($piece, 2)],
        ])->assertOk();

        $performa->refresh();
        $this->assertSame($creditor->id, $performa->creditor_id, 'the creditor stays once a payment exists');
        $this->assertSame('8', $this->onHand($piece));
        $this->assertSame('5.000', $this->onHand($gram), 'the paid grams line is untouched');
        $this->assertSame('3.000', (string) $gramItem->fresh()->weight_settled);
        $this->assertSame(PerformaStatus::PARTIAL, $performa->status);

        // Dropping every editable line is fine while a locked line remains.
        $this->actingAs($this->admin)->putJson(route('branch.performas.update', $performa), [
            'performa_date' => now()->toDateString(),
            'creditor_uuid' => $creditor->uuid,
            'lines' => [],
        ])->assertOk();
        $this->assertSame('10', $this->onHand($piece));
        $this->assertSame(1, $performa->items()->count());

        // Settled or cancelled performas cannot be edited.
        $this->actingAs($this->admin)->postJson(route('branch.performas.return', $performa), ['return_all' => true])->assertOk();
        $this->actingAs($this->admin)->putJson(route('branch.performas.update', $performa), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($piece, 1)],
        ])->assertForbidden();
    }

    public function test_voiding_a_performa_settlement_puts_the_grams_back_on_the_performa(): void
    {
        $gram = $this->gramLot('10');
        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $this->creditor()->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($gram, 6)],
        ])->assertOk();
        $performa = Performa::query()->latest('id')->firstOrFail();
        $item = $performa->items()->firstOrFail();

        $this->actingAs($this->admin)->postJson(route('branch.performas.settle.store', $performa), [
            'payment_method' => 'cash',
            'lines' => [['performa_item_id' => $item->id, 'quantity' => 6, 'price' => '5.00']],
        ])->assertOk();
        $this->assertSame(PerformaStatus::SETTLED, $performa->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('branch.invoices.void', Invoice::query()->latest('id')->firstOrFail()), ['void_reason' => 'Entered by mistake today'])
            ->assertRedirect();

        $this->assertSame('0.000', (string) $item->fresh()->weight_settled);
        $this->assertSame(6.0, (float) $item->fresh()->outstandingAmount());
        $this->assertSame(PerformaStatus::OPEN, $performa->fresh()->status);
        $this->assertSame('4.000', $this->onHand($gram), 'the grams are still with the creditor');
    }

    public function test_the_performa_print_words_grams_and_the_screens_load(): void
    {
        $gram = $this->gramLot('10');
        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $this->creditor()->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($gram, 5.5)],
        ])->assertOk();
        $performa = Performa::query()->latest('id')->firstOrFail();

        $print = $this->actingAs($this->admin)->get(route('branch.performas.print', $performa))->assertOk()->getContent();
        $this->assertStringContainsString('5.5 g', $print);

        $this->actingAs($this->admin)->get(route('branch.performas.show', $performa))->assertOk()->assertSee('5.5 g');
        $this->actingAs($this->admin)->get(route('branch.performas.settle', $performa))->assertOk();
    }

    // ----------------------------------------------------------- documents

    public function test_invoice_and_quotation_print_a_gram_line_as_grams_and_rate_per_gram(): void
    {
        $gram = $this->gramLot('10');
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket([$this->line($gram, 5.5, 5)], ['payment_method' => 'cash']))->assertOk();
        $invoice = Invoice::query()->latest('id')->firstOrFail();

        $html = $this->actingAs($this->admin)->get(route('branch.invoices.print', $invoice))->assertOk()->getContent();
        $this->assertStringContainsString('5.5 g', $html);
        $this->assertStringContainsString('per g', $html);
        $this->assertStringContainsString('27.50', $html);
    }

    public function test_the_pos_catalog_offers_a_gram_lot_while_it_has_grams(): void
    {
        $gram = $this->gramLot('4');

        $items = $this->actingAs($this->admin)->getJson(route('branch.quotations.search', ['catalog' => 1]))->assertOk()->json('data.items');
        $row = collect($items)->firstWhere('line.stone_id', $gram->id);
        $this->assertNotNull($row, 'a gram lot with weight must be on offer');
        $this->assertStringContainsString('4 g', $row['label']);

        // Sold out: no longer offered.
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket([$this->line($gram, 4, 5)], ['payment_method' => 'cash']))->assertOk();
        $items = $this->actingAs($this->admin)->getJson(route('branch.quotations.search', ['catalog' => 1]))->json('data.items');
        $this->assertNull(collect($items)->firstWhere('line.stone_id', $gram->id));
        $this->actingAs($this->admin)->postJson(route('branch.quotations.lookup'), ['code' => $gram->stone_code])->assertStatus(422);
    }
}
