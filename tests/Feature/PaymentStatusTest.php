<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Stone;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoices and delivery notes start pending: no payment is recorded and they
 * can still be edited. Recording a payment (at creation or later) locks them.
 * A paid delivery note turned into an invoice carries its payment over, and
 * only the difference (the tax) is paid on top.
 */
class PaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->admin = User::factory()->admin(Branch::factory()->create()->id)->create();
    }

    private function lot(): Stone
    {
        $this->actingAs($this->admin)->post(route('branch.stones.store'), [
            'name' => 'Lot', 'status' => 'in_stock', 'pricing_mode' => 'per_piece', 'price_per_piece' => '10', 'pieces' => 20,
        ])->assertRedirect();

        return Stone::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function ticket(Stone $stone, int $qty, array $extra = []): array
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
            'lines' => [['line_type' => 'loose_stone', 'stone_id' => $stone->id, 'quantity' => $qty, 'price_per_piece' => 10]],
        ], $extra);
    }

    private function onHand(Stone $stone): int
    {
        return (int) $stone->fresh()->quantity;
    }

    public function test_a_pending_invoice_has_no_payment_and_can_be_edited_until_one_is_recorded(): void
    {
        $stone = $this->lot();

        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket($stone, 5, ['payment_status' => 'pending']))->assertOk();
        $invoice = Invoice::query()->firstOrFail();

        $this->assertSame(PaymentStatus::PENDING, $invoice->payment_status);
        $this->assertNull($invoice->payment_method);
        $this->assertSame(0, $invoice->payments()->count());
        $this->assertSame('50.00', (string) $invoice->grand_total);
        $this->assertSame(15, $this->onHand($stone));
        $this->assertTrue($invoice->canBeEdited());

        // The POS opens it for editing with its line.
        $page = $this->actingAs($this->admin)->get(route('branch.pos.show', ['invoice' => $invoice->uuid]))->assertOk();
        $loaded = $page->viewData('editingDocument');
        $this->assertSame('invoice', $loaded['type']);
        $this->assertSame($invoice->invoice_number, $loaded['number']);
        $this->assertSame(5, $loaded['lines'][0]['quantity']);
        $this->assertSame(20, $loaded['lines'][0]['available_quantity'], 'its own 5 count as on hand again');

        // Edit: 5 -> 8 pieces. Stock moves by the difference only.
        $this->actingAs($this->admin)->putJson(route('branch.invoices.update', $invoice), $this->ticket($stone, 8))
            ->assertOk()->assertJsonPath('data.show_url', route('branch.invoices.show', $invoice));
        $invoice->refresh();
        $this->assertSame('80.00', (string) $invoice->grand_total);
        $this->assertSame(12, $this->onHand($stone));
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame($invoice->invoice_number, Invoice::query()->firstOrFail()->invoice_number, 'same document, same number');

        // The page offers Edit and Record payment.
        $this->actingAs($this->admin)->get(route('branch.invoices.show', $invoice))
            ->assertOk()->assertSee('Record payment')->assertSee(route('branch.pos.show', ['invoice' => $invoice->uuid]), false);

        // Recording the payment locks it.
        $this->actingAs($this->admin)->post(route('branch.invoices.payment', $invoice), ['payment_method' => 'card', 'payment_reference' => 'SLIP-1'])
            ->assertRedirect(route('branch.invoices.show', $invoice));
        $invoice->refresh();
        $this->assertSame(PaymentStatus::PAID, $invoice->payment_status);
        $payment = $invoice->payments()->firstOrFail();
        $this->assertSame('80.00', (string) $payment->amount);
        $this->assertSame('SLIP-1', $payment->payment_reference);
        $this->assertFalse($invoice->canBeEdited());

        $this->actingAs($this->admin)->putJson(route('branch.invoices.update', $invoice), $this->ticket($stone, 2))->assertForbidden();
        $this->actingAs($this->admin)->get(route('branch.pos.show', ['invoice' => $invoice->uuid]))->assertForbidden();
        $this->assertSame(12, $this->onHand($stone));
        $this->actingAs($this->admin)->post(route('branch.invoices.payment', $invoice), ['payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('branch.invoices.show', $invoice))
            ->assertOk()->assertDontSee('Record payment')->assertSee('SLIP-1');
    }

    public function test_an_invoice_marked_paid_in_the_pos_records_its_payment_and_is_locked(): void
    {
        $stone = $this->lot();

        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket($stone, 3, [
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ]))->assertOk();
        $invoice = Invoice::query()->firstOrFail();

        $this->assertSame(PaymentStatus::PAID, $invoice->payment_status);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame('30.00', (string) $invoice->payments()->first()->amount);
        $this->assertFalse($invoice->canBeEdited());

        // "Paid" without a method is refused; a request with no status at all still needs one.
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket($stone, 1, ['payment_status' => 'paid']))->assertStatus(422);
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket($stone, 1))->assertStatus(422);
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_a_delivery_note_is_pending_by_default_editable_and_locked_once_paid(): void
    {
        $stone = $this->lot();

        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket($stone, 4))->assertOk();
        $note = DeliveryNote::query()->firstOrFail();
        $this->assertSame(PaymentStatus::PENDING, $note->payment_status);
        $this->assertSame(0, $note->payments()->count());
        $this->assertSame(16, $this->onHand($stone));

        // Edit: 4 -> 6 pieces and a different client.
        $this->actingAs($this->admin)->putJson(route('branch.delivery-notes.update', $note), $this->ticket($stone, 6, ['walkin_name' => 'Other Client', 'walkin_phone' => '03005550000']))
            ->assertOk();
        $note->refresh();
        $this->assertSame('60.00', (string) $note->grand_total);
        $this->assertSame('Other Client', $note->customer_name_snapshot);
        $this->assertSame(14, $this->onHand($stone));

        $this->actingAs($this->admin)->post(route('branch.delivery-notes.payment', $note), ['payment_method' => 'cash'])->assertRedirect();
        $note->refresh();
        $this->assertSame(PaymentStatus::PAID, $note->payment_status);
        $this->assertSame('60.00', (string) $note->payments()->first()->amount);

        $this->actingAs($this->admin)->putJson(route('branch.delivery-notes.update', $note), $this->ticket($stone, 1))->assertForbidden();
        $this->actingAs($this->admin)->post(route('branch.delivery-notes.cancel', $note), ['cancel_reason' => 'Client changed their mind'])->assertStatus(302);
        $this->assertSame(\App\Enums\DeliveryNoteStatus::ISSUED, $note->fresh()->status, 'a paid note cannot be cancelled');
        $this->assertSame(14, $this->onHand($stone));
    }

    public function test_a_delivery_note_paid_in_the_pos_records_its_payment(): void
    {
        $stone = $this->lot();

        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket($stone, 2, ['payment_status' => 'paid', 'payment_method' => 'bank_transfer', 'payment_reference' => 'TR-9']))->assertOk();
        $note = DeliveryNote::query()->firstOrFail();

        $this->assertSame(PaymentStatus::PAID, $note->payment_status);
        $this->assertSame('20.00', (string) $note->payments()->first()->amount);
        $this->assertFalse($note->canBeEdited());

        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket($stone, 1, ['payment_status' => 'paid']))->assertStatus(422);
    }

    public function test_a_paid_delivery_note_becomes_an_invoice_and_only_the_tax_difference_is_paid(): void
    {
        $this->useTax(true, 21);
        $iva = TaxRate::query()->where('kind', 'iva')->firstOrFail();
        $stone = $this->lot();

        // The note is 29.00 net and was paid in full on the spot.
        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket($stone, 1, [
            'lines' => [['line_type' => 'loose_stone', 'stone_id' => $stone->id, 'quantity' => 1, 'price_per_piece' => 29]],
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ]))->assertOk();
        $note = DeliveryNote::query()->firstOrFail();
        $this->assertSame('29.00', (string) $note->grand_total);

        // The convert dialog shows the difference.
        $this->actingAs($this->admin)->get(route('branch.delivery-notes.show', $note))
            ->assertOk()->assertSee('data-convert-form', false)->assertSee('To pay now');

        // IVA 21% -> 35.09; 29.00 is already paid, 6.09 is paid by card now.
        $this->actingAs($this->admin)->post(route('branch.delivery-notes.convert', $note), [
            'tax_rate_uuid' => $iva->uuid,
            'payment_status' => 'paid',
            'payment_method' => 'card',
            'payment_reference' => 'DIFF',
        ])->assertRedirect();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('35.09', (string) $invoice->grand_total);
        $this->assertSame(PaymentStatus::PAID, $invoice->payment_status);
        $payments = $invoice->payments()->get();
        $this->assertCount(2, $payments);
        $this->assertSame(['29.00', '6.09'], $payments->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $this->assertSame('card', $payments->last()->payment_method->value);
        $this->assertEqualsWithDelta(35.09, $invoice->paidAmount(), 0.001);
        $this->assertEqualsWithDelta(0.0, $invoice->balanceDue(), 0.001);
        $this->assertSame(1, $note->fresh()->payments()->count(), 'the note keeps its own record');
    }

    public function test_the_difference_can_be_left_pending_and_paid_later_from_the_invoice(): void
    {
        $this->useTax(true, 21);
        $iva = TaxRate::query()->where('kind', 'iva')->firstOrFail();
        $stone = $this->lot();

        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket($stone, 1, [
            'lines' => [['line_type' => 'loose_stone', 'stone_id' => $stone->id, 'quantity' => 1, 'price_per_piece' => 29]],
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ]))->assertOk();
        $note = DeliveryNote::query()->firstOrFail();

        $this->actingAs($this->admin)->post(route('branch.delivery-notes.convert', $note), [
            'tax_rate_uuid' => $iva->uuid,
            'payment_status' => 'pending',
        ])->assertRedirect();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(PaymentStatus::PENDING, $invoice->payment_status);
        $this->assertEqualsWithDelta(6.09, $invoice->balanceDue(), 0.001);
        $this->assertFalse($invoice->canBeEdited(), 'it came from a delivery note and already has a payment');

        $this->actingAs($this->admin)->post(route('branch.invoices.payment', $invoice), ['payment_method' => 'cash'])->assertRedirect();
        $invoice->refresh();
        $this->assertSame(PaymentStatus::PAID, $invoice->payment_status);
        $this->assertSame('6.09', (string) $invoice->payments()->reorder()->latest('id')->first()->amount);
    }

    public function test_an_unpaid_delivery_note_converts_to_a_pending_invoice_or_a_paid_one(): void
    {
        $stone = $this->lot();
        foreach ([1, 2] as $qty) {
            $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket($stone, $qty))->assertOk();
        }
        [$first, $second] = DeliveryNote::query()->orderBy('id')->get()->all();

        $this->actingAs($this->admin)->post(route('branch.delivery-notes.convert', $first), ['tax_rate_uuid' => '', 'payment_status' => 'pending'])->assertRedirect();
        $pending = $first->fresh()->invoice;
        $this->assertSame(PaymentStatus::PENDING, $pending->payment_status);
        $this->assertSame(0, $pending->payments()->count());

        // A paid conversion without a method is refused and nothing changes.
        $this->actingAs($this->admin)->post(route('branch.delivery-notes.convert', $second), ['tax_rate_uuid' => '', 'payment_status' => 'paid'])->assertSessionHasErrors('payment_method');
        $this->assertSame(\App\Enums\DeliveryNoteStatus::ISSUED, $second->fresh()->status);

        $this->actingAs($this->admin)->post(route('branch.delivery-notes.convert', $second), ['tax_rate_uuid' => '', 'payment_status' => 'paid', 'payment_method' => 'cash'])->assertRedirect();
        $paid = $second->fresh()->invoice;
        $this->assertSame(PaymentStatus::PAID, $paid->payment_status);
        $this->assertSame('20.00', (string) $paid->payments()->first()->amount);
    }

    public function test_the_pos_shows_the_switch_and_opens_a_delivery_note_for_editing(): void
    {
        $stone = $this->lot();
        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket($stone, 2))->assertOk();
        $note = DeliveryNote::query()->firstOrFail();

        $this->actingAs($this->admin)->get(route('branch.pos.show'))
            ->assertOk()->assertSee('data-pay-state="pending"', false)->assertSee('data-pay-state="paid"', false);

        $page = $this->actingAs($this->admin)->get(route('branch.pos.show', ['delivery_note' => $note->uuid]))->assertOk();
        $this->assertSame('delivery_note', $page->viewData('editingDocument')['type']);
        $this->assertSame(2, $page->viewData('editingDocument')['lines'][0]['quantity']);
    }

    public function test_a_quotation_converts_to_a_pending_invoice_and_reports_show_what_is_awaited(): void
    {
        $stone = $this->lot();

        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket($stone, 2))->assertOk();
        $quotation = \App\Models\Quotation::query()->firstOrFail();
        $this->actingAs($this->admin)->post(route('branch.quotations.convert', $quotation), ['payment_status' => 'pending'])->assertRedirect();
        $this->assertSame(PaymentStatus::PENDING, Invoice::query()->firstOrFail()->payment_status);

        // One paid invoice (30.00), one pending invoice (20.00 above), one unpaid delivery note (40.00).
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket($stone, 3, ['payment_status' => 'paid', 'payment_method' => 'cash']))->assertOk();
        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket($stone, 4))->assertOk();

        $filter = \App\Support\Reports\ReportFilter::currentMonth($this->admin->branch_id);
        $awaiting = app(\App\Repositories\Reports\SalesReportRepository::class)->awaitingPayment($this->admin->branch_id);
        $this->assertSame(1, $awaiting['invoices']);
        $this->assertEqualsWithDelta(20.0, $awaiting['invoice_due'], 0.001);
        $this->assertSame(1, $awaiting['notes']);
        $this->assertEqualsWithDelta(40.0, $awaiting['note_due'], 0.001);

        // Every invoice counts as a sale; the payment-method report keeps pending apart.
        $report = app(\App\Services\Reports\AdminReportService::class)->build('sales-by-payment-method', $filter);
        $labels = $report->rows->pluck('net_revenue', 'payment_method');
        $this->assertEqualsWithDelta(30.0, (float) $labels['Cash'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $labels['Pending (not paid yet)'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) app(\App\Services\Reports\AdminReportService::class)->build('sales-summary', $filter)->totals['net_revenue'], 0.001);

        $cards = collect($this->actingAs($this->admin)->get(route('branch.dashboard'))->viewData('dashboard')['secondary_cards'])->keyBy('key');
        $this->assertStringContainsString('60.00', $cards['awaiting_payment']['value']);
    }

    public function test_every_existing_invoice_gets_a_payment_row_from_the_migration(): void
    {
        $stone = $this->lot();
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket($stone, 1, ['payment_status' => 'paid', 'payment_method' => 'cash']))->assertOk();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(Invoice::query()->firstOrFail()->id, Payment::query()->first()->payable_id);
    }
}
