<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\RunsStoneImports;
use Tests\TestCase;

/**
 * Stone import history: counts per import, and the download of the rows
 * that were not inserted.
 */
class StoneImportHistoryTest extends TestCase
{
    use RefreshDatabase;
    use RunsStoneImports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        Storage::fake('stone-imports');
    }

    /**
     * Run an import of one good and one bad row as the given user.
     */
    private function importPartly(User $user, string $filename = 'supplier.xlsx'): void
    {
        $this->runImport($user, [
            ['name' => 'GOOD', 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2'],
            ['name' => 'BAD', 'pricing_mode' => 'PENDING'],
        ], $filename)->assertOk();
    }

    /**
     * The list shows the file with its total, inserted and rejected counts.
     */
    public function test_history_lists_each_import_with_its_counts(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();
        $this->importPartly($admin);

        $this->actingAs($admin)
            ->get(route('branch.stone-imports.index'))
            ->assertOk()
            ->assertSee('supplier.xlsx')
            ->assertSee('Download');
    }

    /**
     * The download is the Excel of only the rows that were set aside.
     */
    public function test_rejects_download_is_a_workbook_of_the_bad_rows(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();
        $this->importPartly($admin);
        $import = \App\Models\StoneImport::query()->firstOrFail();

        $response = $this->actingAs($admin)
            ->get(route('branch.stone-imports.rejects', $import))
            ->assertOk()
            ->assertDownload('supplier-rows-to-fix.xlsx');

        $rows = IOFactory::load($response->baseResponse->getFile()->getPathname())
            ->getActiveSheet()->toArray(null, false, false, false);

        $this->assertCount(2, $rows);
        $this->assertSame('BAD', $rows[1][0]);
        $this->assertSame('Problem', end($rows[0]));
    }

    /**
     * An import with no bad rows has nothing to download.
     */
    public function test_no_download_when_every_row_went_in(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();
        $this->runImport($admin, [
            ['name' => 'GOOD', 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2'],
        ], 'clean.xlsx')->assertOk();
        $import = \App\Models\StoneImport::query()->firstOrFail();

        $this->actingAs($admin)
            ->get(route('branch.stone-imports.rejects', $import))
            ->assertNotFound();
    }

    /**
     * Another branch cannot open or fetch an import it did not run.
     */
    public function test_another_branch_cannot_fetch_the_rejects(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();
        $this->importPartly($admin);
        $import = \App\Models\StoneImport::query()->withoutGlobalScopes()->firstOrFail();

        $stranger = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($stranger)
            ->get(route('branch.stone-imports.rejects', $import))
            ->assertStatus(403);

        $this->actingAs($stranger)
            ->get(route('branch.stone-imports.index'))
            ->assertOk()
            ->assertDontSee('supplier.xlsx');
    }

    /**
     * Cashiers cannot import, so they cannot read the history either.
     */
    public function test_cashier_cannot_open_the_history(): void
    {
        $cashier = User::factory()->cashier(Branch::factory()->create()->id)->create();

        $this->actingAs($cashier)
            ->get(route('branch.stone-imports.index'))
            ->assertForbidden();
    }

    /**
     * An import that never reached its last step is shown as stopped early.
     */
    public function test_an_unfinished_import_is_flagged_in_the_history(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($admin)->postJson(route('branch.stones.import.begin'), [
            'filename' => 'cut-short.xlsx', 'total_rows' => 5,
        ])->assertCreated();

        $this->actingAs($admin)
            ->get(route('branch.stone-imports.index'))
            ->assertOk()
            ->assertSee('cut-short.xlsx')
            ->assertSee('Stopped early');
    }
}
