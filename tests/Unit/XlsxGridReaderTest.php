<?php

namespace Tests\Unit;

use App\Support\Imports\XlsxGridReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The fast .xlsx reader used by the stone import.
 *
 * It exists purely as a speed optimisation, so the tests that matter are the
 * ones proving it returns exactly what PhpSpreadsheet would have returned —
 * if the two ever diverge, imported inventory would be wrong.
 */
class XlsxGridReaderTest extends TestCase
{
    private XlsxGridReader $reader;

    /** @var list<string> Temporary workbooks to clean up */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new XlsxGridReader;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * Write a workbook to a temporary path.
     *
     * @param  list<list<mixed>>  $rows
     * @param  array<string, string>  $formulas Cell reference => formula
     */
    private function workbook(array $rows, array $formulas = []): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');

        foreach ($formulas as $reference => $formula) {
            $sheet->setCellValue($reference, $formula);
        }

        $path = tempnam(sys_get_temp_dir(), 'grid').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->files[] = $path;

        return $path;
    }

    /**
     * Read the same file through PhpSpreadsheet for comparison.
     *
     * @return list<list<string>>
     */
    private function viaPhpSpreadsheet(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);

        return array_map(
            fn (array $cells): array => array_map(fn ($cell): string => trim((string) ($cell ?? '')), $cells),
            $sheet->toArray(null, true, false, false)
        );
    }

    public function test_it_reads_the_same_grid_as_phpspreadsheet(): void
    {
        $path = $this->workbook([
            ['MATERIAL', 'CARAT', 'TOTAL PCS', 'PRICE (PER CT)', 'TOTAL COST'],
            ['AMATISTA', 2.1, 2, 2, 4.2],
            ['CITIRINO', 0, 68, 9, 612],
        ]);

        $this->assertSame($this->viaPhpSpreadsheet($path), $this->reader->rows($path));
    }

    public function test_it_reads_the_cached_result_of_a_formula(): void
    {
        // The supplier sheet totals its rows with formulas; the reader must
        // take the value the spreadsheet already worked out.
        $path = $this->workbook(
            [
                ['MATERIAL', 'WEIGHT', 'RATE', 'TOTAL COST'],
                ['LABRADORITA', 150, 2.5, null],
            ],
            ['D2' => '=B2*C2']
        );

        $rows = $this->reader->rows($path);

        $this->assertSame('375', $rows[1][3]);
        $this->assertSame($this->viaPhpSpreadsheet($path), $rows);
    }

    public function test_it_pads_short_rows_so_columns_stay_aligned(): void
    {
        // A row that stops early must not shift the operator's mapping.
        $path = $this->workbook([
            ['MATERIAL', 'CARAT', 'TRAY NO', 'REMARKS'],
            ['AMATISTA', 2.1, 'D1', 'CARAT'],
            ['CITIRINO'],
        ]);

        $rows = $this->reader->rows($path);

        $this->assertCount(4, $rows[2]);
        $this->assertSame(['CITIRINO', '', '', ''], $rows[2]);
    }

    public function test_it_keeps_blank_cells_in_the_middle_of_a_row(): void
    {
        $path = $this->workbook([
            ['MATERIAL', 'CARAT', 'TRAY NO'],
            ['AMATISTA', null, 'D1'],
        ]);

        $this->assertSame(['AMATISTA', '', 'D1'], $this->reader->rows($path)[1]);
    }

    public function test_it_stops_once_the_row_limit_is_passed(): void
    {
        $rows = [['MATERIAL']];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['AMATISTA'];
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1);

        $this->reader->rows($this->workbook($rows), 5);
    }

    public function test_it_declines_files_it_cannot_stream(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'grid').'.csv';
        file_put_contents($csv, "MATERIAL,CARAT\nAMATISTA,2.1\n");
        $this->files[] = $csv;

        $this->assertFalse($this->reader->supports($csv));
        $this->assertFalse($this->reader->supports(sys_get_temp_dir().'/does-not-exist.xlsx'));
    }

    public function test_it_accepts_a_real_workbook(): void
    {
        $this->assertTrue($this->reader->supports($this->workbook([['MATERIAL'], ['AMATISTA']])));
    }

    /**
     * Excel stores 16.15 as 16.149999999999999 and displays 16.15. The
     * import must read what the sheet shows, not the 17-digit tail.
     */
    public function test_it_reads_numbers_the_way_excel_displays_them(): void
    {
        $path = $this->workbook([
            ['MATERIAL', 'CARAT', 'RATE'],
            ['AMATISTA', 16.15, 4.1],
            ['CITIRINO', 2.1, 77],
        ]);

        // Rewrite the cells with the long values Excel itself writes.
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = str_replace(['<v>16.15</v>', '<v>4.1</v>', '<v>2.1</v>'], ['<v>16.149999999999999</v>', '<v>4.0999999999999996</v>', '<v>2.1000000000000001</v>'], $xml);
        $this->assertStringContainsString('16.149999999999999', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $rows = $this->reader->rows($path);

        $this->assertSame(['AMATISTA', '16.15', '4.1'], $rows[1]);
        $this->assertSame(['CITIRINO', '2.1', '77'], $rows[2]);
    }

    /**
     * Text that merely looks long is never reformatted, and tiny or huge
     * numbers are spelled out rather than left in exponent form.
     */
    public function test_it_leaves_text_alone_and_spells_out_exponents(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['CODE', 'AMOUNT'], [null, 0.001]], null, 'A1');
        // A genuine text cell, so it is stored as a string and not as a number.
        $sheet->setCellValueExplicit('A2', '0.30000000000000004', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'grid').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->files[] = $path;

        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = str_replace('<v>0.001</v>', '<v>1.0000000000000000E-3</v>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $rows = $this->reader->rows($path);

        // The first cell is text, so its digits are kept exactly as typed.
        $this->assertSame('0.30000000000000004', $rows[1][0]);
        $this->assertSame('0.001', $rows[1][1]);
    }
}
