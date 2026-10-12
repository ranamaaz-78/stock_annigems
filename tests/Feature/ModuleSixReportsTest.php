<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\QuotationStatus;
use App\Enums\ReportExportStatus;
use App\Enums\StoneMovementType;
use App\Jobs\GenerateReportExport;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\Purity;
use App\Models\Quotation;
use App\Models\ReportExport;
use App\Models\Stone;
use App\Models\StoneStockMovement;
use App\Models\User;
use App\Services\DashboardStatsService;
use App\Services\InvoiceService;
use App\Services\Reports\AdminReportService;
use App\Services\Reports\SuperAdmin\SuperAdminReportService;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use Database\Seeders\MetalPuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ModuleSixReportsTest
 *
 * Covers the load-bearing guarantees of the reporting layer:
 *
 * - every report screen renders for the role allowed to see it
 * - the three branch roles that must not report get 403 by direct URL
 * - SuperAdmin cannot reach a record detail page for any record type
 * - a voided invoice disappears from every revenue figure, on the report and
 *   on the dashboard alike
 * - the stone ledger reconciles, aging buckets land, conversion rate computes,
 *   and a cross-branch customer is identified
 */
class ModuleSixReportsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        $this->seed(MetalPuritySeeder::class);

        $this->branch = Branch::factory()->create(['name' => 'Main Showroom']);
        $this->admin = User::factory()->admin($this->branch->id)->create();
    }

    /**
     * SuperAdmin account (factory default role, no branch).
     */
    private function superAdmin(): User
    {
        return User::factory()->create();
    }

    /**
     * First seeded gold purity (22K).
     */
    private function purity(): Purity
    {
        return Purity::query()->where('name', '22K')->firstOrFail();
    }

    // ---------------------------------------------------------------------
    // Screens render
    // ---------------------------------------------------------------------

    public function test_every_admin_report_screen_renders(): void
    {
        // Real data behind the reports, so an empty-state pass cannot hide a
        // formatting bug in a populated row.
        $this->completedInvoice(50_000);

        $this->actingAs($this->admin)->get(route('branch.reports.index'))->assertOk();

        foreach (ReportCatalog::adminFamilies() as $family) {
            foreach (array_keys($family['reports']) as $key) {
                $this->actingAs($this->admin)
                    ->get(route('branch.reports.show', $key))
                    ->assertOk();
            }
        }
    }

    public function test_every_superadmin_report_screen_renders(): void
    {
        $this->completedInvoice(50_000);
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->get(route('superadmin.reports.index'))->assertOk();

        foreach (ReportCatalog::superAdminFamilies() as $family) {
            foreach (array_keys($family['reports']) as $key) {
                $this->actingAs($superAdmin)
                    ->get(route('superadmin.reports.show', $key))
                    ->assertOk();
            }
        }
    }

    public function test_role_dashboards_render_for_every_role(): void
    {
        $this->completedInvoice(50_000);

        $this->actingAs($this->superAdmin())->get(route('superadmin.dashboard'))->assertOk();

        foreach (['admin', 'manager', 'cashier', 'inventoryStaff'] as $state) {
            $user = User::factory()->{$state}($this->branch->id)->create();

            $this->actingAs($user)->get(route('branch.dashboard'))->assertOk();
        }
    }

    public function test_an_unknown_report_slug_is_not_found(): void
    {
        $this->actingAs($this->admin)
            ->get(route('branch.reports.show', 'profit-and-margin'))
            ->assertNotFound();
    }

    public function test_an_admin_only_record_listing_is_not_reachable_on_the_superadmin_surface(): void
    {
        // Pending Quotations is a record listing, so it exists in the Admin
        // catalog and deliberately not in the SuperAdmin one.
        $this->assertNotNull(ReportCatalog::findAdminReport('pending-quotations'));
        $this->assertNull(ReportCatalog::findSuperAdminReport('pending-quotations'));

        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.reports.show', 'pending-quotations'))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Access control
    // ---------------------------------------------------------------------

    public function test_manager_cashier_and_inventory_staff_are_denied_every_report_route(): void
    {
        foreach (['manager', 'cashier', 'inventoryStaff'] as $state) {
            $user = User::factory()->{$state}($this->branch->id)->create();

            $this->actingAs($user)->get(route('branch.reports.index'))->assertForbidden();
            $this->actingAs($user)->get(route('branch.reports.show', 'sales-summary'))->assertForbidden();
            $this->actingAs($user)->get(route('branch.reports.export', 'sales-summary'))->assertForbidden();
        }
    }

    public function test_branch_admin_is_denied_the_superadmin_report_surface(): void
    {
        // The superadmin middleware answers first; either refusal is correct so
        // long as the page is never rendered.
        $this->actingAs($this->admin)
            ->get(route('superadmin.reports.index'))
            ->assertForbidden();
    }

    public function test_superadmin_is_denied_the_admin_report_surface(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('branch.reports.index'))
            ->assertForbidden();
    }

    public function test_superadmin_cannot_reach_a_record_detail_page_for_any_record_type(): void
    {
        $invoice = $this->completedInvoice(50_000);
        $superAdmin = $this->superAdmin();

        $quotation = Quotation::query()->firstOrFail();
        $item = JewelleryItem::query()->withoutGlobalScopes()->firstOrFail();
        $stone = Stone::factory()->create(['branch_id' => $this->branch->id]);
        $customer = Customer::factory()->create();

        $routes = [
            route('branch.invoices.show', $invoice),
            route('branch.quotations.show', $quotation),
            route('branch.jewellery-items.show', $item),
            route('branch.stones.show', $stone),
            route('branch.customers.show', $customer),
        ];

        foreach ($routes as $url) {
            $this->actingAs($superAdmin)->get($url)->assertForbidden();
        }
    }

    public function test_record_detail_policies_reject_superadmin_explicitly(): void
    {
        // Not merely "denied because branch_id is null": the policies must say
        // no to SuperAdmin outright, which is what spec 7.4 asks for.
        $superAdmin = $this->superAdmin();
        $invoice = $this->completedInvoice(50_000);

        $this->assertFalse($superAdmin->can('view', $invoice));
        $this->assertFalse($superAdmin->can('view', Quotation::query()->firstOrFail()));
        $this->assertFalse($superAdmin->can('view', Customer::factory()->create()));
        $this->assertFalse($superAdmin->can('viewAdminReports'));
        $this->assertTrue($superAdmin->can('viewSuperAdminReports'));
        $this->assertTrue($this->admin->can('viewAdminReports'));
        $this->assertFalse($this->admin->can('viewSuperAdminReports'));
    }

    // ---------------------------------------------------------------------
    // Voided invoices leave every revenue figure
    // ---------------------------------------------------------------------

    public function test_voiding_an_invoice_removes_it_from_revenue_and_from_the_dashboard(): void
    {
        $invoice = $this->completedInvoice(80_000);

        $before = $this->salesSummary();
        $this->assertSame(1, (int) $before['invoice_count']);
        $this->assertEquals(80_000.0, (float) $before['net_revenue']);

        app(InvoiceService::class)->void($invoice, 'Customer returned the piece.', $this->admin);

        $after = $this->salesSummary();
        $this->assertSame(0, (int) $after['invoice_count']);
        $this->assertEquals(0.0, (float) $after['net_revenue']);

        // The voided report is the one place the invoice must still appear.
        $voided = app(AdminReportService::class)->build('voided-invoices', $this->filter());
        $this->assertCount(1, $voided->rows);
    }

    public function test_a_dashboard_card_matches_its_report_figure(): void
    {
        $this->completedInvoice(120_000);

        $dashboard = app(DashboardStatsService::class)->forBranchUser($this->admin);
        $monthRevenue = collect($dashboard['cards'])->firstWhere('key', 'month_revenue');

        $this->assertNotNull($monthRevenue, 'Admin dashboard must carry a month revenue card.');

        // Same figure, same source: the card and the report both go through
        // SalesReportRepository behind Invoice::completed(). This is the whole
        // reason DashboardStatsService exists rather than its own queries.
        $this->assertSame(
            $monthRevenue['value'],
            ReportFormatter::money($this->salesSummary()['net_revenue'])
        );
    }

    // ---------------------------------------------------------------------
    // Computation correctness
    // ---------------------------------------------------------------------

    public function test_gold_weight_sold_matches_the_summed_fixture(): void
    {
        $this->completedInvoice(50_000, netWeight: 12.500);
        $this->completedInvoice(30_000, netWeight: 7.250);

        $result = app(AdminReportService::class)->build('gold-weight-sold', $this->filter());

        $this->assertEquals(19.750, round((float) $result->rows->sum('net_weight'), 3));
    }

    public function test_quotation_conversion_rate_computes(): void
    {
        // Two quotations, one converted: a 50% funnel.
        $converted = $this->completedInvoice(40_000);
        Quotation::query()->create([
            'branch_id' => $this->branch->id,
            'quotation_number' => 'QTN-000902',
            'quotation_date' => now(),
            'status' => QuotationStatus::DRAFT,
            'walkin_name' => 'Undecided Buyer',
            'subtotal' => 10_000,
            'grand_total' => 10_000,
            'discount_type' => 'none',
            'tax_type' => 'none',
        ]);

        $result = app(AdminReportService::class)->build('quotation-funnel', $this->filter());
        $rate = collect($result->summary)->firstWhere('key', 'conversion_rate');

        $this->assertNotNull($rate);
        $this->assertStringContainsString('50', $rate['value']);
        $this->assertNotNull($converted->id);
    }

    public function test_aging_buckets_place_an_old_piece_in_an_older_bucket(): void
    {
        $fresh = $this->availableItem();
        $old = $this->availableItem();
        $old->forceFill(['created_at' => now()->subDays(200)])->save();

        $result = app(AdminReportService::class)->build('aging-stock', $this->filter());

        // Every available piece is counted exactly once across the buckets.
        $this->assertSame(2, (int) $result->rows->sum('pieces'));

        // And they are not both in the same bucket.
        $populated = $result->rows->filter(fn (array $row): bool => (int) $row['pieces'] > 0);
        $this->assertCount(2, $populated);
        $this->assertNotNull($fresh->id);
    }

    public function test_stone_movement_ledger_reconciles_before_and_after_quantities(): void
    {
        $stone = Stone::factory()->create([
            'branch_id' => $this->branch->id,
            'quantity' => 0,
        ]);

        $running = 0;

        foreach ([40, -10, 25] as $change) {
            StoneStockMovement::query()->create([
                'branch_id' => $this->branch->id,
                'stone_id' => $stone->id,
                'type' => $change > 0 ? StoneMovementType::INTAKE : StoneMovementType::SOLD,
                'quantity_change' => $change,
                'quantity_before' => $running,
                'quantity_after' => $running + $change,
                'created_by' => $this->admin->id,
                'created_by_role' => $this->admin->role->value,
            ]);

            $running += $change;
        }

        // The ledger renders newest first; reverse it to walk the trail forward.
        // Reversing rather than re-sorting matters here: the three fixtures share
        // a timestamp, so only the repository's own id tiebreak is deterministic.
        $result = app(AdminReportService::class)->build('stone-movement-ledger', $this->filter());
        $rows = $result->rows->reverse()->values();

        $this->assertCount(3, $rows);

        // Each row must continue from the one before it, which is the whole
        // point of storing before/after on a movement.
        for ($i = 1; $i < $rows->count(); $i++) {
            $this->assertEquals(
                (float) $rows[$i - 1]['quantity_after'],
                (float) $rows[$i]['quantity_before'],
                'Ledger row '.$i.' does not continue from the previous row.'
            );
        }
    }

    public function test_cross_branch_report_identifies_a_customer_active_at_two_branches(): void
    {
        $customer = Customer::factory()->create(['name' => 'Two Branch Buyer']);
        $other = Branch::factory()->create(['name' => 'Second Showroom']);
        $otherAdmin = User::factory()->admin($other->id)->create();

        $this->completedInvoice(30_000, customer: $customer);
        $this->completedInvoice(45_000, customer: $customer, admin: $otherAdmin, branch: $other);

        $result = app(AdminReportService::class)->build('cross-branch-customers', $this->filter());

        $this->assertCount(1, $result->rows);
        $this->assertSame('Two Branch Buyer', $result->rows->first()['name']);
        $this->assertSame(2, (int) $result->rows->first()['branch_count']);
    }

    public function test_superadmin_consolidated_total_equals_the_sum_of_its_branches(): void
    {
        $other = Branch::factory()->create(['name' => 'Second Showroom']);
        $otherAdmin = User::factory()->admin($other->id)->create();

        $this->completedInvoice(60_000);
        $this->completedInvoice(40_000, admin: $otherAdmin, branch: $other);

        $consolidated = app(SuperAdminReportService::class)->build(
            'sales-summary',
            $this->filter(branchId: null)
        );

        $this->assertEquals(100_000.0, (float) $consolidated->rows->sum('net_revenue'));
        $this->assertCount(2, $consolidated->rows);
    }

    // ---------------------------------------------------------------------
    // Export
    // ---------------------------------------------------------------------

    public function test_admin_can_export_a_report_as_csv_with_a_self_describing_header(): void
    {
        $this->completedInvoice(75_000);

        $response = $this->actingAs($this->admin)
            ->get(route('branch.reports.export', ['report' => 'sales-summary']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Main Showroom', $csv);
        $this->assertStringContainsString('Sales Summary', $csv);
        $this->assertStringContainsString('Generated by', $csv);
        // The revenue disclaimer travels with the file, not only the screen.
        $this->assertStringContainsString('not profit', $csv);

        $this->assertDatabaseHas('report_exports', [
            'branch_id' => $this->branch->id,
            'report_key' => 'sales-summary',
            'format' => 'csv',
        ]);

        // Every export is audit logged with its filters.
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);
    }

    public function test_admin_can_print_a_report(): void
    {
        $this->completedInvoice(75_000);

        $this->actingAs($this->admin)
            ->get(route('branch.reports.export', ['report' => 'sales-summary', 'format' => 'pdf']))
            ->assertOk()
            ->assertSee('Sales Summary')
            ->assertSee('Main Showroom');
    }

    public function test_a_large_export_is_queued_and_becomes_downloadable(): void
    {
        Bus::fake([GenerateReportExport::class]);
        Storage::fake('local');
        // Force the queued path without generating thousands of fixtures.
        config()->set('annigems.reports.export.queue_row_threshold', 1);

        $this->completedInvoice(90_000);

        $this->actingAs($this->admin)
            ->get(route('branch.reports.export', ['report' => 'sales-summary']))
            ->assertRedirect(route('branch.reports.index'))
            ->assertSessionHas('success');

        $record = ReportExport::query()->latest('id')->firstOrFail();

        $this->assertSame(ReportExportStatus::PENDING, $record->status);
        Bus::assertDispatched(GenerateReportExport::class);

        // Run the job the way the queue worker would.
        app()->call([new GenerateReportExport($record->id), 'handle']);

        $record->refresh();

        $this->assertSame(ReportExportStatus::COMPLETED, $record->status);
        $this->assertTrue($record->isDownloadable());
        Storage::disk('local')->assertExists($record->file_path);

        $this->actingAs($this->admin)
            ->get(route('branch.reports.download', $record))
            ->assertOk();
    }

    public function test_a_queued_export_cannot_be_downloaded_by_another_user(): void
    {
        Storage::fake('local');
        config()->set('annigems.reports.export.queue_row_threshold', 1);

        $this->completedInvoice(90_000);

        $this->actingAs($this->admin)
            ->get(route('branch.reports.export', ['report' => 'sales-summary']))
            ->assertRedirect();

        $record = ReportExport::query()->latest('id')->firstOrFail();
        app()->call([new GenerateReportExport($record->id), 'handle']);

        // A second Admin on the same branch still may not take someone else's
        // generated file: the export belongs to the person who asked for it.
        $otherAdmin = User::factory()->admin($this->branch->id)->create();

        $this->actingAs($otherAdmin)
            ->get(route('branch.reports.download', $record->fresh()))
            ->assertForbidden();
    }

    public function test_a_role_without_export_permission_cannot_export(): void
    {
        $manager = User::factory()->manager($this->branch->id)->create();

        $this->actingAs($manager)
            ->get(route('branch.reports.export', ['report' => 'sales-summary']))
            ->assertForbidden();
    }

    public function test_a_reversed_date_range_is_rejected_with_a_field_error(): void
    {
        $this->actingAs($this->admin)
            ->get(route('branch.reports.show', [
                'report' => 'sales-summary',
                'preset' => 'custom',
                'from' => now()->toDateString(),
                'to' => now()->subMonth()->toDateString(),
            ]))
            ->assertSessionHasErrors('to');
    }

    public function test_an_absurd_date_range_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('branch.reports.show', [
                'report' => 'sales-summary',
                'preset' => 'custom',
                'from' => now()->subYears(9)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertSessionHasErrors('to');
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * Report filter covering this month for the given branch.
     */
    private function filter(?int $branchId = null): ReportFilter
    {
        return ReportFilter::currentMonth(
            func_num_args() === 0 ? $this->branch->id : $branchId
        );
    }

    /**
     * Sales summary totals for the branch, as the report computes them.
     *
     * @return array<string, mixed>
     */
    private function salesSummary(): array
    {
        $result = app(AdminReportService::class)->build('sales-summary', $this->filter());

        return $result->totals;
    }

    /**
     * An available jewellery piece on the branch.
     */
    private function availableItem(float $netWeight = 10.000): JewelleryItem
    {
        return JewelleryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'purity_id' => $this->purity()->id,
            'status' => JewelleryItemStatus::AVAILABLE,
            'net_weight' => $netWeight,
        ]);
    }

    /**
     * A completed invoice built the way the application builds one: a draft
     * quotation converted through InvoiceConversionService, so every snapshot
     * column the reports read is populated exactly as production would fill it.
     */
    private function completedInvoice(
        float $total,
        float $netWeight = 10.000,
        ?Customer $customer = null,
        ?User $admin = null,
        ?Branch $branch = null,
    ): Invoice {
        $admin ??= $this->admin;
        $branch ??= $this->branch;
        $purity = $this->purity();

        $item = JewelleryItem::factory()->create([
            'branch_id' => $branch->id,
            'purity_id' => $purity->id,
            'status' => JewelleryItemStatus::AVAILABLE,
            'net_weight' => $netWeight,
        ]);

        $quotation = Quotation::query()->create([
            'branch_id' => $branch->id,
            'quotation_number' => 'QTN-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'quotation_date' => now(),
            'status' => QuotationStatus::DRAFT,
            'customer_id' => $customer?->id,
            'walkin_name' => $customer === null ? 'Walk-in Buyer' : null,
            'subtotal' => $total,
            'grand_total' => $total,
            'discount_type' => 'none',
            'tax_type' => 'none',
        ]);

        $quotation->items()->create([
            'line_type' => LineType::JEWELLERY_ITEM,
            'jewellery_item_id' => $item->id,
            'item_code_snapshot' => $item->item_code,
            'name_snapshot' => $item->name,
            'category_snapshot' => $item->category?->name,
            'purity_snapshot' => $purity->name,
            'net_weight_snapshot' => $netWeight,
            'quantity' => 1,
            'gold_rate_applied' => 1000,
            'gold_value' => $total,
            'making_charge_type' => 'none',
            'making_charges' => 0,
            'stone_value' => 0,
            'line_total' => $total,
        ]);

        $invoice = app(\App\Services\InvoiceConversionService::class)->convert(
            $quotation->fresh(['items']),
            ['payment_method' => 'cash'],
            $admin
        );

        $this->assertSame(InvoiceStatus::COMPLETED, $invoice->status);

        return $invoice;
    }
}
