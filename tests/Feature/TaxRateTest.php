<?php

namespace Tests\Feature;

use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\Purity;
use App\Models\Quotation;
use App\Models\TaxRate;
use App\Models\User;
use Database\Seeders\MetalPuritySeeder;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tax rates: the IVA and Recargo de equivalencia lists SuperAdmin manages, and
 * the way a document picks one and keeps a snapshot of it.
 */
class TaxRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->seed(MetalPuritySeeder::class);
    }

    private function rate(string $kind, string $name): TaxRate
    {
        return TaxRate::query()->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function actors(): array
    {
        $branch = Branch::factory()->create();

        return [User::factory()->create(), User::factory()->admin($branch->id)->create()];
    }

    /**
     * A POS ticket with one jewellery line and the chosen rates.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ticket(User $admin, array $overrides = []): array
    {
        $purity = Purity::query()->where('name', '22K')->firstOrFail();
        $item = JewelleryItem::factory()->create([
            'branch_id' => $admin->branch_id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
        ]);

        return array_merge([
            'walkin_name' => 'Maria Garcia',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'discount_value' => 0,
            'tax_type' => 'none',
            'tax_rate' => 0,
            'apply_tax' => true,
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

    public function test_the_seeder_fills_the_lists_once_and_never_puts_back_what_was_deleted(): void
    {
        $this->seed(TaxRateSeeder::class);

        $this->assertSame(['IVA 21%', 'IVA 10%', 'IVA 4%', 'Exempt'], TaxRate::query()->ofKind('iva')->pluck('name')->all());
        $this->assertSame(['Recargo 5.2%', 'Recargo 1.4%', 'Recargo 0.5%', 'Recargo 0.75%'], TaxRate::query()->ofKind('recargo')->pluck('name')->all());
        $this->assertSame('IVA 21%', TaxRate::query()->where('is_default', true)->value('name'));
        $this->assertTrue((bool) $this->rate('iva', 'Exempt')->is_exempt);

        $this->rate('iva', 'IVA 4%')->delete();
        $this->seed(TaxRateSeeder::class);
        $this->assertSame(0, TaxRate::query()->where('name', 'IVA 4%')->count());
        $this->assertSame(7, TaxRate::query()->count());
    }

    public function test_superadmin_manages_both_lists(): void
    {
        [$super] = $this->actors();
        $this->seed(TaxRateSeeder::class);

        $this->actingAs($super)->get(route('superadmin.tax-rates.index'))
            ->assertOk()
            ->assertSee('IVA 21%')
            ->assertSee('Recargo 5.2%');

        // Add an IVA rate and a recargo rate: nothing about them is fixed in code.
        $this->actingAs($super)->post(route('superadmin.tax-rates.store'), ['kind' => 'iva', 'name' => 'IVA 7%', 'rate' => '7'])->assertRedirect();
        $this->actingAs($super)->post(route('superadmin.tax-rates.store'), ['kind' => 'recargo', 'name' => 'Recargo 2%', 'rate' => '2'])->assertRedirect();
        $seven = $this->rate('iva', 'IVA 7%');
        $this->assertSame('7.000', (string) $seven->rate);
        $this->assertFalse($seven->is_default);
        $this->assertSame(1, $this->rate('recargo', 'Recargo 2%')->sort_order > 0 ? 1 : 0);

        // Edit.
        $this->actingAs($super)->put(route('superadmin.tax-rates.update', $seven), ['name' => 'IVA 7.5%', 'rate' => '7.5'])->assertRedirect();
        $this->assertSame('7.500', (string) $seven->fresh()->rate);

        // An exempt rate keeps no percentage, whatever was typed.
        $this->actingAs($super)->post(route('superadmin.tax-rates.store'), ['kind' => 'iva', 'name' => 'Exempt export', 'is_exempt' => 1])->assertRedirect();
        $exempt = $this->rate('iva', 'Exempt export');
        $this->assertTrue((bool) $exempt->is_exempt);
        $this->assertSame('0.000', (string) $exempt->rate);

        // Default moves; the default cannot be turned off or deleted.
        $this->actingAs($super)->post(route('superadmin.tax-rates.default', $seven))->assertRedirect();
        $this->assertTrue($seven->fresh()->is_default);
        $this->assertFalse($this->rate('iva', 'IVA 21%')->is_default);
        $this->actingAs($super)->post(route('superadmin.tax-rates.toggle', $seven))->assertSessionHasErrors('rate');
        $this->actingAs($super)->delete(route('superadmin.tax-rates.destroy', $seven))->assertSessionHasErrors('rate');
        $this->assertTrue($seven->fresh()->is_active);

        // Turn one off and delete another.
        $four = $this->rate('iva', 'IVA 4%');
        $this->actingAs($super)->post(route('superadmin.tax-rates.toggle', $four))->assertRedirect();
        $this->assertFalse($four->fresh()->is_active);
        $this->actingAs($super)->delete(route('superadmin.tax-rates.destroy', $four))->assertRedirect();
        $this->assertSoftDeleted($four);

        // A percentage over 100 is refused.
        $this->actingAs($super)->post(route('superadmin.tax-rates.store'), ['kind' => 'iva', 'name' => 'Too much', 'rate' => '120'])->assertSessionHasErrors('rate');
    }

    public function test_branch_users_cannot_reach_the_tax_rates(): void
    {
        [, $admin] = $this->actors();
        $this->seed(TaxRateSeeder::class);
        $rate = $this->rate('iva', 'IVA 21%');

        $this->actingAs($admin)->get(route('superadmin.tax-rates.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('superadmin.tax-rates.store'), ['kind' => 'iva', 'name' => 'IVA 1%', 'rate' => '1'])->assertForbidden();
        $this->actingAs($admin)->delete(route('superadmin.tax-rates.destroy', $rate))->assertForbidden();
        $this->assertSame(8, TaxRate::query()->count());
    }

    public function test_a_document_uses_the_chosen_iva_and_recargo_and_the_server_sets_the_percentages(): void
    {
        [, $admin] = $this->actors();
        $this->seed(TaxRateSeeder::class);
        $ten = $this->rate('iva', 'IVA 10%');
        $re = $this->rate('recargo', 'Recargo 1.4%');

        $this->actingAs($admin)->postJson(route('branch.quotations.store'), $this->ticket($admin, [
            'tax_rate_uuid' => $ten->uuid,
            'recargo_rate_uuid' => $re->uuid,
            // A crafted percentage is ignored.
            'tax_type' => 'percentage',
            'tax_rate' => 1,
        ]))->assertOk();

        $quotation = Quotation::query()->latest('id')->firstOrFail();
        $this->assertSame('IVA 10%', $quotation->tax_name);
        $this->assertSame('10.00', (string) $quotation->tax_rate);
        $this->assertSame('Recargo 1.4%', $quotation->recargo_name);
        $this->assertSame('1.400', (string) $quotation->recargo_rate);

        $base = (float) $quotation->subtotal - (float) $quotation->discount_amount;
        $this->assertEqualsWithDelta(round($base * 0.10, 2), (float) $quotation->tax_amount, 0.005);
        $this->assertEqualsWithDelta(round($base * 0.014, 2), (float) $quotation->recargo_amount, 0.005);
        $this->assertEqualsWithDelta($base + (float) $quotation->tax_amount + (float) $quotation->recargo_amount, (float) $quotation->grand_total, 0.005);

        // The printed totals name both lines.
        $html = $this->actingAs($admin)->get(route('branch.quotations.print', $quotation))->assertOk()->getContent();
        $this->assertStringContainsString('IVA 10%', $html);
        $this->assertStringContainsString('Recargo de equivalencia (1.4%)', $html);
    }

    public function test_no_rate_chosen_uses_the_default_iva_and_no_recargo(): void
    {
        [, $admin] = $this->actors();
        $this->seed(TaxRateSeeder::class);

        $payload = $this->ticket($admin);
        unset($payload['apply_tax']);
        $this->actingAs($admin)->postJson(route('branch.quotations.store'), $payload)->assertOk();

        $quotation = Quotation::query()->latest('id')->firstOrFail();
        $this->assertSame('IVA 21%', $quotation->tax_name);
        $this->assertSame('0.00', (string) $quotation->recargo_amount);
        $this->assertNull($quotation->recargo_name);
    }

    public function test_exempt_has_no_amount_and_says_so_on_the_document(): void
    {
        [, $admin] = $this->actors();
        $this->seed(TaxRateSeeder::class);

        $this->actingAs($admin)->postJson(route('branch.quotations.store'), $this->ticket($admin, [
            'tax_rate_uuid' => $this->rate('iva', 'Exempt')->uuid,
        ]))->assertOk();

        $quotation = Quotation::query()->latest('id')->firstOrFail();
        $this->assertSame('Exempt', $quotation->tax_name);
        $this->assertSame('0.00', (string) $quotation->tax_amount);

        $html = $this->actingAs($admin)->get(route('branch.quotations.print', $quotation))->getContent();
        $this->assertStringContainsString('Exempt', $html);
    }

    public function test_apply_tax_false_and_tax_switched_off_give_no_tax(): void
    {
        [, $admin] = $this->actors();
        $this->seed(TaxRateSeeder::class);

        $this->actingAs($admin)->postJson(route('branch.quotations.store'), $this->ticket($admin, [
            'apply_tax' => false,
            'tax_rate_uuid' => $this->rate('iva', 'IVA 21%')->uuid,
            'recargo_rate_uuid' => $this->rate('recargo', 'Recargo 5.2%')->uuid,
        ]))->assertOk();
        $quotation = Quotation::query()->latest('id')->firstOrFail();
        $this->assertSame('0.00', (string) $quotation->tax_amount);
        $this->assertSame('0.00', (string) $quotation->recargo_amount);
        $this->assertNull($quotation->tax_name);

        app(\App\Services\SettingService::class)->setGroup(\App\Enums\SettingGroup::TAX, ['tax_enabled' => false], User::factory()->create());
        $this->actingAs($admin)->postJson(route('branch.quotations.store'), $this->ticket($admin, [
            'tax_rate_uuid' => $this->rate('iva', 'IVA 21%')->uuid,
        ]))->assertOk();
        $this->assertSame('0.00', (string) Quotation::query()->latest('id')->firstOrFail()->tax_amount);
    }

    public function test_a_document_keeps_its_snapshot_when_the_rate_changes_or_is_deleted(): void
    {
        [$super, $admin] = $this->actors();
        $this->seed(TaxRateSeeder::class);
        $ten = $this->rate('iva', 'IVA 10%');

        $this->actingAs($admin)->postJson(route('branch.quotations.invoice'), $this->ticket($admin, [
            'tax_rate_uuid' => $ten->uuid,
            'payment_method' => 'cash',
        ]))->assertOk();
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $amount = (string) $invoice->tax_amount;

        $this->actingAs($super)->put(route('superadmin.tax-rates.update', $ten), ['name' => 'IVA 12%', 'rate' => '12'])->assertRedirect();
        $this->actingAs($super)->delete(route('superadmin.tax-rates.destroy', $ten))->assertRedirect();

        $fresh = $invoice->fresh();
        $this->assertSame('IVA 10%', $fresh->tax_name);
        $this->assertSame('10.00', (string) $fresh->tax_rate);
        $this->assertSame($amount, (string) $fresh->tax_amount);
        $this->actingAs($admin)->get(route('branch.invoices.show', $fresh))->assertOk()->assertSee('IVA 10%');

        // A deleted rate cannot be chosen any more.
        $this->actingAs($admin)->postJson(route('branch.quotations.store'), $this->ticket($admin, [
            'tax_rate_uuid' => $ten->uuid,
        ]))->assertStatus(422);
    }

    public function test_the_pos_offers_the_lists_and_a_delivery_note_never_takes_tax(): void
    {
        [, $admin] = $this->actors();
        $this->seed(TaxRateSeeder::class);

        $this->actingAs($admin)->get(route('branch.pos.show'))
            ->assertOk()
            ->assertSee('data-iva-select', false)
            ->assertSee('IVA 21%')
            ->assertSee('IVA 4%')
            ->assertSee('Exempt')
            ->assertSee('data-recargo-select', false)
            ->assertSee('Recargo 5.2%');

        $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), $this->ticket($admin, [
            'tax_rate_uuid' => $this->rate('iva', 'IVA 21%')->uuid,
        ]))->assertOk();
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(
            (string) \App\Models\DeliveryNote::query()->firstOrFail()->subtotal,
            (string) \App\Models\DeliveryNote::query()->firstOrFail()->grand_total
        );
    }

    public function test_recargo_can_be_added_on_its_own_without_iva(): void
    {
        [, $admin] = $this->actors();
        $this->seed(TaxRateSeeder::class);

        // The POS sends the IVA key as null when IVA is off, so the default IVA is not pulled in.
        $this->actingAs($admin)->postJson(route('branch.quotations.store'), $this->ticket($admin, [
            'tax_rate_uuid' => null,
            'recargo_rate_uuid' => $this->rate('recargo', 'Recargo 5.2%')->uuid,
        ]))->assertOk();

        $quotation = Quotation::query()->latest('id')->firstOrFail();
        $this->assertNull($quotation->tax_name);
        $this->assertSame('0.00', (string) $quotation->tax_amount);
        $this->assertSame('Recargo 5.2%', $quotation->recargo_name);
        $base = (float) $quotation->subtotal - (float) $quotation->discount_amount;
        $this->assertEqualsWithDelta(round($base * 0.052, 2), (float) $quotation->recargo_amount, 0.005);
        $this->assertEqualsWithDelta($base + (float) $quotation->recargo_amount, (float) $quotation->grand_total, 0.005);
    }
}
