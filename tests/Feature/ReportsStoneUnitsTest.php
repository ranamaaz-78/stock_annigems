<?php

namespace Tests\Feature;

use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Creditor;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Stone;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Reports\AdminReportService;
use App\Support\Reports\ReportFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reports after pricing: stone lots counted in pieces or weighed in
 * grams, valued at their own rate; IVA + recargo on the tax report; and the
 * goods still out on performas and delivery notes.
 */
class ReportsStoneUnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->admin = User::factory()->admin(Branch::factory()->create()->id)->create();
    }

    private function report(string $key): \App\Support\Reports\ReportResult
    {
        return app(AdminReportService::class)->build($key, ReportFilter::currentMonth($this->admin->branch_id));
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

    /** 10 pcs x 7 = 70.00 */
    private function pieceLot(): Stone
    {
        return $this->lot(['pricing_mode' => 'per_piece', 'price_per_piece' => '7', 'pieces' => 10]);
    }

    /** 5 ct x 40 = 200.00 (the carats are the stock) */
    private function caratLot(): Stone
    {
        return $this->lot(['pricing_mode' => 'per_carat', 'carat' => '5.000', 'price_per_carat' => '40']);
    }

    /** 10 g x 5 = 50.00 */
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function ticket(array $lines, array $extra = []): array
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
        ], $extra);
    }

    public function test_stock_and_valuation_count_each_lot_in_its_own_unit(): void
    {
        $piece = $this->pieceLot();
        $carat = $this->caratLot();
        $gram = $this->gramLot();

        $stock = $this->report('stone-stock-summary');
        $this->assertSame('Rs 320.00', $this->plainMoney($stock->summary[2]['value']));
        $this->assertEqualsWithDelta(320.0, (float) $stock->totals['value'], 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $stock->totals['total_carat'], 0.001, 'only the carat lot has carat');

        $labels = $stock->rows->pluck('on_hand_label', 'stone_code');
        $this->assertSame('10 pcs', $labels[$piece->stone_code]);
        $this->assertSame('5 ct', $labels[$carat->stone_code]);
        $this->assertSame('10 g', $labels[$gram->stone_code]);

        $valuation = $this->report('stone-valuation');
        $this->assertEqualsWithDelta(320.0, (float) $valuation->totals['value'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $valuation->rows->firstWhere('stone_code', $carat->stone_code)['value'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $valuation->rows->firstWhere('stone_code', $gram->stone_code)['value'], 0.001);
        $byMode = collect($valuation->meta['extra_tables'][0]['rows'])->keyBy('pricing');
        $this->assertCount(3, $byMode);
        $this->assertEqualsWithDelta(10.0, (float) $byMode['Per gram price']['grams'], 0.001);

        // A deleted lot is out of stock and out of the valuation.
        $this->actingAs($this->admin)->delete(route('branch.stones.destroy', $carat))->assertRedirect();
        $this->assertEqualsWithDelta(120.0, (float) $this->report('stone-valuation')->totals['value'], 0.001);
    }

    public function test_a_gram_lot_is_not_out_of_stock_until_its_weight_is_gone(): void
    {
        $gram = $this->gramLot();
        $piece = $this->pieceLot();

        // 10 g is at the 10 g reorder threshold: low, not out.
        $low = $this->report('stone-low-stock');
        $row = $low->rows->firstWhere('stone_code', $gram->stone_code);
        $this->assertSame('Low stock', $row['severity']);
        $this->assertNull($low->rows->firstWhere('stone_code', $piece->stone_code), '10 pieces is above the piece threshold');

        // Sell all 10 g: now it is out.
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket([$this->line($gram, 10)], ['payment_method' => 'cash']))->assertOk();
        $out = $this->report('stone-low-stock')->rows->firstWhere('stone_code', $gram->stone_code);
        $this->assertSame('Out of stock', $out['severity']);

        $this->assertSame(1, app(\App\Repositories\Reports\StoneReportRepository::class)->zeroStockCount($this->admin->branch_id));
    }

    public function test_consumption_and_ledger_show_pieces_and_grams(): void
    {
        $piece = $this->pieceLot();
        $gram = $this->gramLot();
        $creditor = Creditor::factory()->create(['branch_id' => $this->admin->branch_id]);

        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket([$this->line($piece, 3), $this->line($gram, 4)], ['payment_method' => 'cash']))->assertOk();
        $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket([$this->line($gram, 2)]))->assertOk();
        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [$this->line($piece, 2)],
        ])->assertOk();
        // A draft quotation only holds stone: it is not consumption.
        $this->actingAs($this->admin)->postJson(route('branch.quotations.store'), $this->ticket([$this->line($piece, 1)]))->assertOk();

        $consumption = $this->report('stone-consumption');
        $pieceRow = $consumption->rows->firstWhere('stone_code', $piece->stone_code);
        $gramRow = $consumption->rows->firstWhere('stone_code', $gram->stone_code);
        $this->assertSame('3', $pieceRow['sold_label']);
        $this->assertSame('2', $pieceRow['on_credit_label']);
        $this->assertSame('5', $pieceRow['total_out_label'], 'the quotation hold is not consumption');
        $this->assertSame('4 g', $gramRow['sold_label']);
        $this->assertSame('2 g', $gramRow['delivered_label']);
        $this->assertSame('6 g', $gramRow['total_out_label']);

        $ledger = $this->report('stone-movement-ledger');
        $gramMoves = $ledger->rows->where('stone_code', $gram->stone_code);
        $sold = $gramMoves->firstWhere('type', StoneMovementType::SOLD->label());
        $this->assertSame('-4 g', $sold['quantity_change']);
        $this->assertSame('10 g', $sold['quantity_before']);
        $this->assertSame('6 g', $sold['quantity_after']);

        $volume = collect($ledger->meta['extra_tables'][0]['rows']);
        $this->assertEqualsWithDelta(6.0, (float) $volume->sum('grams_out'), 0.001, '4 g sold + 2 g delivered');
        $this->assertEqualsWithDelta(10.0, (float) $volume->sum('grams_in'), 0.001, 'the 10 g intake');
    }

    public function test_loose_stone_sales_count_a_gram_sale_in_grams(): void
    {
        $piece = $this->pieceLot();
        $gram = $this->gramLot();

        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket(
            [$this->line($piece, 3), $this->line($gram, 4)],
            ['payment_method' => 'cash']
        ))->assertOk();

        $report = $this->report('loose-stone-sales');
        $this->assertSame(3, (int) $report->totals['quantity']);
        $this->assertEqualsWithDelta(4.0, (float) $report->totals['grams'], 0.001);
        // 3 pcs at the lot price and 4 g x 5.00 = 20.00.
        $gramRow = $report->rows->firstWhere('stone_code', $gram->stone_code);
        $this->assertEqualsWithDelta(20.0, (float) $gramRow['revenue'], 0.001);
        $this->assertSame(0, (int) $gramRow['quantity']);
    }

    public function test_tax_report_splits_iva_and_recargo(): void
    {
        $this->useTax(true, 21);
        $iva = TaxRate::query()->where('kind', 'iva')->firstOrFail();
        $recargo = TaxRate::query()->create(['kind' => 'recargo', 'name' => 'Recargo 5.2%', 'rate' => 5.2, 'is_active' => true]);
        $piece = $this->pieceLot();

        // 2 x 10.00 = 20.00: IVA 4.20 + recargo 1.04.
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket(
            [array_merge($this->line($piece, 2), ['price_per_piece' => 10])],
            ['payment_method' => 'cash', 'apply_tax' => true, 'tax_rate_uuid' => $iva->uuid, 'recargo_rate_uuid' => $recargo->uuid]
        ))->assertOk();
        // 20.00 with IVA only.
        $this->actingAs($this->admin)->postJson(route('branch.quotations.invoice'), $this->ticket(
            [array_merge($this->line($piece, 2), ['price_per_piece' => 10])],
            ['payment_method' => 'cash', 'apply_tax' => true, 'tax_rate_uuid' => $iva->uuid]
        ))->assertOk();

        $tax = $this->report('tax-collected');
        $this->assertEqualsWithDelta(8.40, (float) $tax->totals['tax_total'], 0.001);
        $this->assertEqualsWithDelta(1.04, (float) $tax->totals['recargo_total'], 0.001);
        $byRate = collect($tax->meta['extra_tables'][0]['rows']);
        $this->assertCount(2, $byRate);
        $this->assertEqualsWithDelta(1.04, (float) $byRate->firstWhere('recargo_name', 'Recargo 5.2%')['recargo_total'], 0.001);
        $this->assertEqualsWithDelta(Invoice::query()->sum('recargo_amount'), (float) $tax->totals['recargo_total'], 0.001);

        $summary = $this->report('sales-summary');
        $sources = collect($summary->meta['extra_tables'][0]['rows']);
        $this->assertSame(2, (int) $sources->firstWhere('source', 'Direct invoice')['invoice_count']);
    }

    public function test_performa_and_delivery_note_reports_show_what_is_still_out(): void
    {
        $piece = $this->pieceLot();
        $gram = $this->gramLot();
        $creditor = Creditor::factory()->create(['branch_id' => $this->admin->branch_id]);

        // Issued: 4 pcs x 10.00 = 40.00 and 5 g x 6.00 = 30.00.
        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [array_merge($this->line($piece, 4), ['price_per_piece' => 10]), array_merge($this->line($gram, 5), ['price_per_piece' => 6])],
        ])->assertOk();
        $performa = \App\Models\Performa::query()->firstOrFail();
        $pieceItem = $performa->items()->where('stone_id', $piece->id)->firstOrFail();
        $gramItem = $performa->items()->where('stone_id', $gram->id)->firstOrFail();

        // Pay for 1 piece (10.00) and send back 2 g (12.00).
        $this->actingAs($this->admin)->postJson(route('branch.performas.settle.store', $performa), [
            'payment_method' => 'cash',
            'lines' => [['performa_item_id' => $pieceItem->id, 'quantity' => 1, 'price' => '10.00']],
        ])->assertOk();
        $this->actingAs($this->admin)->postJson(route('branch.performas.return', $performa), [
            'lines' => [['performa_item_id' => $gramItem->id, 'quantity' => 2]],
        ])->assertOk();

        $report = $this->report('performa-outstanding');
        $row = $report->rows->firstWhere('creditor_code', $creditor->creditor_code);
        $this->assertEqualsWithDelta(70.0, (float) $row['issued'], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $row['settled'], 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $row['returned'], 0.001);
        $this->assertEqualsWithDelta(48.0, (float) $row['outstanding'], 0.001, '70 issued - 10 paid - 12 returned');
        $this->assertEqualsWithDelta(48.0, (float) $report->totals['outstanding'], 0.001);
        $this->assertSame($performa->performa_number, collect($report->meta['extra_tables'][0]['rows'])->first()['performa_number']);

        // Delivery notes: one stays out, one is turned into an invoice, one is cancelled.
        foreach ([1, 2, 3] as $qty) {
            $this->actingAs($this->admin)->postJson(route('branch.delivery-notes.store'), $this->ticket([array_merge($this->line($piece, 1), ['price_per_piece' => 10 * $qty])]))->assertOk();
        }
        [$stays, $invoiced, $cancelled] = DeliveryNote::query()->orderBy('id')->get()->all();
        $this->actingAs($this->admin)->post(route('branch.delivery-notes.convert', $invoiced), ['payment_method' => 'cash'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('branch.delivery-notes.cancel', $cancelled), ['cancel_reason' => 'Client changed their mind'])->assertRedirect();

        $notes = $this->report('delivery-notes');
        $byStatus = collect($notes->meta['extra_tables'][0]['rows'])->keyBy('status');
        $this->assertSame(1, (int) $byStatus['issued']['notes']);
        $this->assertSame(1, (int) $byStatus['invoiced']['notes']);
        $this->assertSame(1, (int) $byStatus['cancelled']['notes']);
        $this->assertEqualsWithDelta(10.0, (float) $byStatus['issued']['value'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $byStatus['invoiced']['value'], 0.001);
        $waiting = collect($notes->meta['extra_tables'][1]['rows']);
        $this->assertCount(1, $waiting);
        $this->assertSame($stays->delivery_note_number, $waiting->first()['delivery_note_number']);

        // Every report renders and exports (the export throttle is not what is under test).
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        foreach (['stone-stock-summary', 'stone-valuation', 'stone-low-stock', 'stone-consumption', 'stone-movement-ledger', 'loose-stone-sales', 'tax-collected', 'performa-outstanding', 'delivery-notes'] as $key) {
            $this->actingAs($this->admin)->get(route('branch.reports.show', $key))->assertOk();
            $this->actingAs($this->admin)->get(route('branch.reports.export', $key))->assertOk();
        }
    }

    public function test_dashboards_count_stone_in_pieces_grams_and_value(): void
    {
        $this->pieceLot();
        $this->caratLot();
        $gram = $this->gramLot();
        $creditor = Creditor::factory()->create(['branch_id' => $this->admin->branch_id]);
        $this->actingAs($this->admin)->postJson(route('branch.performas.store'), [
            'creditor_uuid' => $creditor->uuid,
            'performa_date' => now()->toDateString(),
            'lines' => [array_merge($this->line($gram, 4), ['price_per_piece' => 6])],
        ])->assertOk();

        $admin = $this->actingAs($this->admin)->get(route('branch.dashboard'))->assertOk();
        $cards = collect($admin->viewData('dashboard')['secondary_cards'])->keyBy('key');
        // Only the piece lot is counted in pieces; carat and gram lots are measured.
        $this->assertSame('10', $cards['total_stones']['value']);
        $this->assertStringContainsString('6 g', str_replace('.000', '', $cards['total_stones']['hint']));
        // 10 x 7 + 5 ct x 40 + 6 g x 5 = 300.00 of stone in hand.
        $this->assertStringContainsString('300.00', $cards['stone_value']['value']);
        // 4 g issued at 6.00 is out with a creditor.
        $this->assertStringContainsString('24.00', $cards['out_with_clients']['value']);

        $inventory = User::factory()->inventoryStaff($this->admin->branch_id)->create();
        $this->actingAs($inventory)->get(route('branch.dashboard'))->assertOk();
    }

    private function plainMoney(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value));
    }
}
