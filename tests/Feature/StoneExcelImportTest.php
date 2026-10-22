<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Stone;
use App\Models\StoneImport;
use App\Models\StoneStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsStoneImports;
use Tests\TestCase;

/**
 * The Excel import wizard: read the sheet, confirm the columns, review the
 * costed rows, then insert. Covers the three pricing modes as the supplier
 * sheet writes them, plus the rows that have to come back red.
 */
class StoneExcelImportTest extends TestCase
{
    use RefreshDatabase;
    use RunsStoneImports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * Build a workbook with the supplier sheet's own header row.
     *
     * @param  list<list<string|int|float>>  $rows
     */
    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray([
            'SR. NO', 'MATERIAL', 'SHAPE/CUT', 'CARAT', 'DIMENSION', 'TOTAL PCS',
            'PAIRS', 'WEIGHT IN GMS / KGS', 'PRICE (PER CT)', 'PRICE (PER PIECE)',
            'STATUS', 'TOTAL COST', 'MEASUREMENT', 'TRAY NO', 'REMARKS',
        ], null, 'A1');

        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'stones').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'RAW DATA.xlsx', null, null, true);
    }

    /**
     * One row per pricing mode, exactly as the sheet writes them.
     *
     * @return list<list<string|int|float>>
     */
    private function sampleRows(): array
    {
        return [
            [1, 'LABRADORITA', 'N/A', 'N/A', '0x0', 0, 0, 150, 2.5, 0, 'IN STOCK', 375, 'PER GMS PRICE', '', 'GMS'],
            [2, 'CITIRINO', 'PERA', 'N/A', '8x6', 68, 0, 0, 0, 9, 'IN STOCK', 612, 'PER PIECE PRICE', 'K1', 'BOX'],
            [3, 'AMATISTA', 'N/A', 2.1, '6x0', 2, 0, 0, 2, 0, 'IN STOCK', 4.2, 'PER CARAT PRICE', 'D1', 'CARAT'],
        ];
    }

    /**
     * Stage the sheet and return the envelope data.
     *
     * @return array<string, mixed>
     */
    private function stage(User $actor, UploadedFile $file): array
    {
        return $this->actingAs($actor)
            ->post(route('branch.stones.import.stage'), ['file' => $file])
            ->assertOk()
            ->json('data');
    }

    /**
     * Headers are matched to our fields without the operator doing anything.
     */
    public function test_columns_are_auto_matched_from_the_header_row(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $data = $this->stage($admin, $this->workbook($this->sampleRows()));

        $this->assertSame(3, $data['row_count']);
        $this->assertSame(1, $data['mapping']['name']);
        $this->assertSame(2, $data['mapping']['shape']);
        $this->assertSame(3, $data['mapping']['carat']);
        $this->assertSame(5, $data['mapping']['pieces']);
        $this->assertSame(8, $data['mapping']['price_per_carat']);
        $this->assertSame(9, $data['mapping']['price_per_piece']);
        // The sheet keeps the gram rate in the per-carat column.
        $this->assertSame(8, $data['mapping']['price_per_gram']);
        $this->assertSame(12, $data['mapping']['pricing_mode']);
    }

    /**
     * Every mode is costed with our own formula, not the sheet's total.
     */
    public function test_preview_costs_each_pricing_mode(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $staged = $this->stage($admin, $this->workbook($this->sampleRows()));

        $preview = $this->actingAs($admin)
            ->postJson(route('branch.stones.import.preview'), [
                'token' => $staged['token'],
                'mapping' => $staged['mapping'],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $preview['ok']);
        $this->assertSame(0, $preview['errors']);

        [$gram, $piece, $carat] = $preview['rows'];

        // JSON drops a trailing .0, so totals are compared by value.
        $this->assertSame('per_gram', $gram['pricing_mode']);
        $this->assertEqualsWithDelta(375, $gram['total_cost'], 0.0001);
        $this->assertNull($gram['piece_price']);

        $this->assertSame('per_piece', $piece['pricing_mode']);
        $this->assertEqualsWithDelta(612, $piece['total_cost'], 0.0001);

        $this->assertSame('per_carat', $carat['pricing_mode']);
        $this->assertEqualsWithDelta(4.2, $carat['piece_price'], 0.0001);
        $this->assertEqualsWithDelta(8.4, $carat['total_cost'], 0.0001);
    }

    /**
     * A row the sheet cannot cost comes back with its reasons, ready to edit.
     */
    public function test_unusable_rows_are_reported_instead_of_guessed(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $rows = $this->sampleRows();
        $rows[] = [4, 'ZAFIRO', 'N/A', 'PENDING', 'PENDING', 0, 0, 0, 'PENDING', 0, 'IN STOCK', 0, 'PENDING', 'D1', ''];

        $staged = $this->stage($admin, $this->workbook($rows));

        $preview = $this->actingAs($admin)
            ->postJson(route('branch.stones.import.preview'), [
                'token' => $staged['token'],
                'mapping' => $staged['mapping'],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $preview['ok']);
        $this->assertSame(1, $preview['errors']);

        $broken = $preview['rows'][3];
        $this->assertNotEmpty($broken['issues']);
        $this->assertEqualsWithDelta(0, $broken['total_cost'], 0.0001);
        $this->assertNull($broken['cost_formula']);
    }

    /**
     * Fixing the mode in the grid turns the row green with no re-upload.
     */
    public function test_editing_a_row_rechecks_it(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $recheck = $this->actingAs($admin)
            ->postJson(route('branch.stones.import.recheck'), [
                'rows' => [[
                    'name' => 'ZAFIRO',
                    'pricing_mode' => 'per_carat',
                    'carat' => '3.59',
                    'pieces' => '25',
                    'price_per_carat' => '75',
                ]],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $recheck['ok']);
        $this->assertSame(0, $recheck['errors']);
        $this->assertEqualsWithDelta(269.25, $recheck['rows'][0]['piece_price'], 0.0001);
        $this->assertEqualsWithDelta(6731.25, $recheck['rows'][0]['total_cost'], 0.0001);
    }

    /**
     * Insert writes every lot with its own code, barcode, and opening stock.
     */
    public function test_insert_creates_lots_with_codes_barcodes_and_stock(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $staged = $this->stage($admin, $this->workbook($this->sampleRows()));

        $preview = $this->actingAs($admin)
            ->postJson(route('branch.stones.import.preview'), [
                'token' => $staged['token'],
                'mapping' => $staged['mapping'],
            ])
            ->json('data');

        $this->runImport($admin, $preview['rows'])
            ->assertOk()
            ->assertJsonPath('data.inserted', 3)
            ->assertJsonPath('data.rejected', 0);

        $stones = Stone::query()->orderBy('id')->get();
        $this->assertSame(['STN-000001', 'STN-000002', 'STN-000003'], $stones->pluck('stone_code')->all());
        $this->assertSame(3, $stones->pluck('barcode')->unique()->count());

        foreach ($stones as $stone) {
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{5}$/', $stone->barcode);
        }

        $citirino = $stones->firstWhere('name', 'CITIRINO');
        $this->assertSame('612.000', (string) $citirino->total_cost);
        $this->assertSame(68, (int) $citirino->quantity);
        $this->assertSame('K1', $citirino->tray_no);

        $amatista = $stones->firstWhere('name', 'AMATISTA');
        $this->assertSame('4.200', (string) $amatista->piece_price);
        $this->assertSame('8.400', (string) $amatista->total_cost);

        // Gram lots are bulk weight and open no piece ledger.
        $labradorita = $stones->firstWhere('name', 'LABRADORITA');
        $this->assertSame(0, (int) $labradorita->quantity);
        $this->assertSame('150.000', (string) $labradorita->weight_grams);

        // Two piece lots open a piece ledger row; the gram lot opens a weight row.
        $this->assertSame(3, StoneStockMovement::query()->count());
        $this->assertSame(
            '150.000',
            (string) StoneStockMovement::query()->where('stone_id', $labradorita->id)->value('weight_after')
        );
    }

    /**
     * A tampered total cannot get past the server; ours is recalculated.
     */
    public function test_client_supplied_totals_are_recalculated(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->runImport($admin, [[
            'name' => 'CITIRINO',
            'pricing_mode' => 'per_piece',
            'pieces' => '10',
            'price_per_piece' => '5',
            'total_cost' => '1',
            'piece_price' => '1',
        ]])->assertOk();

        $this->assertSame('50.000', (string) Stone::query()->first()->total_cost);
    }

    /**
     * Rows that pass are inserted; rows that fail are kept in the import
     * history as an Excel in the sheet's own columns, with a Problem column.
     * Nothing is sent back to download on the spot.
     */
    public function test_good_rows_insert_and_bad_rows_are_kept_in_the_history(): void
    {
        Storage::fake('stone-imports');
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->runImport($admin, [
            ['name' => 'GOOD', 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2'],
            ['name' => 'BAD', 'pricing_mode' => 'PENDING', 'pieces' => '0', 'remarks' => '=1+1'],
        ], 'supplier.xlsx', [], ['name' => 'MATERIAL', 'remarks' => 'REMARKS'])
            ->assertOk()
            ->assertJsonPath('data.inserted', 1)
            ->assertJsonPath('data.rejected', 1)
            ->assertJsonMissingPath('data.rejects_file');

        $this->assertSame(['GOOD'], Stone::query()->pluck('name')->all());

        $import = StoneImport::query()->firstOrFail();
        $this->assertSame('supplier.xlsx', $import->filename);
        $this->assertSame([2, 1, 1], [$import->total_rows, $import->inserted_rows, $import->rejected_rows]);
        $this->assertTrue($import->isFinished());

        $rows = IOFactory::load(Storage::disk('stone-imports')->path($import->rejects_path))
            ->getActiveSheet()->toArray(null, false, false, false);

        $this->assertCount(2, $rows);
        $this->assertSame('MATERIAL', $rows[0][0]);
        $this->assertSame('Problem', end($rows[0]));
        $this->assertSame('BAD', $rows[1][0]);
        $this->assertNotSame('', end($rows[1]));
        // A formula-looking cell stays text.
        $this->assertSame('=1+1', $rows[1][array_search('REMARKS', $rows[0], true)]);
    }

    /**
     * With nothing ready, nothing is inserted but the attempt is recorded.
     */
    public function test_a_batch_with_no_ready_rows_inserts_nothing(): void
    {
        Storage::fake('stone-imports');
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->runImport($admin, [['name' => 'BAD', 'pricing_mode' => 'PENDING']])
            ->assertOk()
            ->assertJsonPath('data.inserted', 0)
            ->assertJsonPath('data.rejected', 1);

        $this->assertSame(0, Stone::query()->count());
    }

    /**
     * A missing name or an over-long cell sets only that row aside.
     */
    public function test_missing_or_oversized_cells_reject_only_their_row(): void
    {
        Storage::fake('stone-imports');
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->runImport($admin, [
            ['name' => 'GOOD', 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2'],
            ['name' => '', 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2'],
            ['name' => str_repeat('X', 200), 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2'],
        ])
            ->assertOk()
            ->assertJsonPath('data.inserted', 1)
            ->assertJsonPath('data.rejected', 2);

        $this->assertSame(1, Stone::query()->count());
    }

    /**
     * A fully valid import has no rows-to-fix file.
     */
    public function test_a_fully_valid_import_has_no_rejects_file(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->runImport($admin, [['name' => 'GOOD', 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2']])
            ->assertOk()
            ->assertJsonPath('data.rejected', 0);

        $import = StoneImport::query()->firstOrFail();
        $this->assertSame(1, $import->inserted_rows);
        $this->assertNull($import->rejects_path);
    }

    /**
     * The rows-to-fix file is the uploaded sheet's own layout: every original
     * column in its original place with the original values, plus Problem.
     */
    public function test_rejects_file_keeps_the_original_columns_and_values(): void
    {
        Storage::fake('stone-imports');
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $badRow = [4, 'RUBYBAD', 'ROUND', 'N/A', '5x5', 3, 0, 0, 0, 0, 'IN STOCK', 0, 'PER PIECE PRICE', 'Z9', 'BOX'];
        $staged = $this->stage($admin, $this->workbook(array_merge($this->sampleRows(), [$badRow])));

        $preview = $this->actingAs($admin)
            ->postJson(route('branch.stones.import.preview'), [
                'token' => $staged['token'],
                'mapping' => $staged['mapping'],
            ])
            ->json('data');

        $this->runImport(
            $admin, $preview['rows'], 'supplier.xlsx', [], [], 100, $staged['token'], $staged['mapping']
        )
            ->assertOk()
            ->assertJsonPath('data.inserted', 3)
            ->assertJsonPath('data.rejected', 1);

        $import = StoneImport::query()->firstOrFail();
        $rows = IOFactory::load(Storage::disk('stone-imports')->path($import->rejects_path))
            ->getActiveSheet()->toArray(null, false, false, false);

        $this->assertCount(2, $rows);
        // Original header row, in the original order, then Problem.
        $this->assertSame(
            ['SR. NO', 'MATERIAL', 'SHAPE/CUT', 'CARAT', 'DIMENSION', 'TOTAL PCS', 'PAIRS', 'WEIGHT IN GMS / KGS',
                'PRICE (PER CT)', 'PRICE (PER PIECE)', 'STATUS', 'TOTAL COST', 'MEASUREMENT', 'TRAY NO', 'REMARKS', 'Problem'],
            $rows[0]
        );
        // Original values, including the serial number and the sheet's own wording.
        $this->assertSame(
            // The test sheet builder leaves 0 cells blank, so they are blank in the original too.
            ['4', 'RUBYBAD', 'ROUND', 'N/A', '5x5', '3', '', '', '', '', 'IN STOCK', '', 'PER PIECE PRICE', 'Z9', 'BOX'],
            array_map('strval', array_slice($rows[1], 0, 15))
        );
        $this->assertNotSame('', (string) $rows[1][15]);
    }

    /**
     * Many rows go in over several batches and add up.
     */
    public function test_rows_are_inserted_over_several_batches(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->runImport($admin, array_fill(0, 7, ['name' => 'GOOD', 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2']), 'big.xlsx', [], [], 3)
            ->assertOk()
            ->assertJsonPath('data.inserted', 7);

        $import = StoneImport::query()->firstOrFail();
        $this->assertSame(3, $import->chunks_done);
        $this->assertSame(7, Stone::query()->count());
        $this->assertSame(7, $import->total_rows);
    }

    /**
     * A batch sent twice cannot insert twice, and a finished import is shut.
     */
    public function test_a_batch_cannot_be_applied_twice_or_after_finish(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $uuid = $this->actingAs($admin)->postJson(route('branch.stones.import.begin'), [
            'filename' => 'x.xlsx', 'total_rows' => 2,
        ])->json('data.import_uuid');

        $send = fn (int $index) => $this->actingAs($admin)->postJson(route('branch.stones.import.chunk'), [
            'import_uuid' => $uuid, 'chunk_index' => $index, 'rows' => [['name' => 'GOOD', 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2']],
        ]);

        $send(0)->assertOk();
        $send(0)->assertStatus(422);
        $this->assertSame(1, Stone::query()->count());

        $this->actingAs($admin)->postJson(route('branch.stones.import.finish'), [
            'import_uuid' => $uuid,
        ])->assertOk();

        $send(1)->assertStatus(422);
        $this->assertSame(1, Stone::query()->count());
    }

    /**
     * Another branch cannot add rows to an import it does not own.
     */
    public function test_another_branch_cannot_write_into_my_import(): void
    {
        $mine = User::factory()->admin(Branch::factory()->create()->id)->create();
        $other = User::factory()->admin(Branch::factory()->create()->id)->create();

        $uuid = $this->actingAs($mine)->postJson(route('branch.stones.import.begin'), [
            'filename' => 'x.xlsx', 'total_rows' => 1,
        ])->json('data.import_uuid');

        $this->actingAs($other)->postJson(route('branch.stones.import.chunk'), [
            'import_uuid' => $uuid, 'chunk_index' => 0, 'rows' => [['name' => 'GOOD', 'pricing_mode' => 'per_piece', 'pieces' => '5', 'price_per_piece' => '2']],
        ])->assertStatus(422);

        $this->assertSame(0, Stone::query()->withoutGlobalScopes()->count());
    }

    /**
     * Cashiers cannot import.
     */
    public function test_cashier_cannot_import(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier($branch->id)->create();

        $this->actingAs($cashier)
            ->post(route('branch.stones.import.stage'), ['file' => $this->workbook($this->sampleRows())])
            ->assertForbidden();
    }

    /**
     * A staged upload belongs to its branch only.
     */
    public function test_another_branch_cannot_preview_a_staged_upload(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $adminA = User::factory()->admin($branchA->id)->create();
        $adminB = User::factory()->admin($branchB->id)->create();

        $staged = $this->stage($adminA, $this->workbook($this->sampleRows()));

        $this->actingAs($adminB)
            ->postJson(route('branch.stones.import.preview'), [
                'token' => $staged['token'],
                'mapping' => $staged['mapping'],
            ])
            ->assertStatus(422);
    }

    /**
     * An optional last BARCODE column: a value is kept, an empty cell gets a
     * generated number, and a repeated value is refused.
     */
    public function test_barcode_column_is_kept_or_generated_and_duplicates_are_refused(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            'MATERIAL', 'PRICING MODE', 'CARAT', 'TOTAL PIECES', 'PRICE PER CARAT', 'BARCODE',
        ], null, 'A1');
        $sheet->fromArray([
            ['AMATISTA', 'PER CARAT', 2, 1, 10, '123456'],
            ['CITRINO', 'PER CARAT', 2, 1, 10, ''],
            ['TOPACIO', 'PER CARAT', 2, 1, 10, '123456'],
        ], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'stones').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $staged = $this->stage($admin, new UploadedFile($path, 'barcodes.xlsx', null, null, true));
        $this->assertSame(5, $staged['mapping']['barcode']);

        $preview = $this->actingAs($admin)
            ->postJson(route('branch.stones.import.preview'), ['token' => $staged['token'], 'mapping' => $staged['mapping']])
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $preview['ok'], json_encode(array_column($preview['rows'], 'issues')));
        $this->assertSame(1, $preview['errors']);

        $begin = $this->actingAs($admin)
            ->postJson(route('branch.stones.import.begin'), ['filename' => 'barcodes.xlsx', 'total_rows' => 2])
            ->assertStatus(201)
            ->json('data');

        $this->actingAs($admin)
            ->postJson(route('branch.stones.import.chunk'), [
                'import_uuid' => $begin['import_uuid'],
                'chunk_index' => 0,
                'rows' => [$preview['rows'][0], $preview['rows'][1]],
            ])
            ->assertOk();

        $this->assertSame('123456', \App\Models\Stone::query()->where('name', 'AMATISTA')->value('barcode'));
        $generated = \App\Models\Stone::query()->where('name', 'CITRINO')->value('barcode');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $generated);
        $this->assertNotSame('123456', $generated);
    }

    /**
     * A barcode already on one of this branch's lots updates that lot with the
     * row's data instead of being refused or creating a second lot.
     */
    public function test_existing_barcode_updates_that_lot(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['MATERIAL', 'PRICING MODE', 'CARAT', 'TOTAL PIECES', 'PRICE PER CARAT', 'BARCODE'], null, 'A1');
        $sheet->fromArray([['AMATISTA', 'PER CARAT', 2, 1, 10, '123456']], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'stones').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $run = function () use ($admin, $path): void {
            $staged = $this->stage($admin, new UploadedFile($path, 'barcodes.xlsx', null, null, true));
            $preview = $this->actingAs($admin)
                ->postJson(route('branch.stones.import.preview'), ['token' => $staged['token'], 'mapping' => $staged['mapping']])
                ->assertOk()->json('data');
            $this->assertSame(1, $preview['ok'], json_encode(array_column($preview['rows'], 'issues')));
            $begin = $this->actingAs($admin)
                ->postJson(route('branch.stones.import.begin'), ['filename' => 'barcodes.xlsx', 'total_rows' => 1])
                ->assertStatus(201)->json('data');
            $this->actingAs($admin)
                ->postJson(route('branch.stones.import.chunk'), [
                    'import_uuid' => $begin['import_uuid'], 'chunk_index' => 0, 'rows' => $preview['rows'],
                ])->assertOk();
        };

        $run();
        $this->assertSame(1, \App\Models\Stone::query()->count());

        // Same barcode, new price: the lot is updated, not duplicated.
        $sheet->setCellValue('E2', 25);
        (new Xlsx($spreadsheet))->save($path);
        $run();

        $this->assertSame(1, \App\Models\Stone::query()->count());
        $this->assertEquals(25, \App\Models\Stone::query()->where('barcode', '123456')->value('price_per_carat'));
    }
}
