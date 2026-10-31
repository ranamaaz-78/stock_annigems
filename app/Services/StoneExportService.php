<?php

namespace App\Services;

use App\Enums\StonePricingMode;
use App\Models\Stone;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * StoneExportService
 *
 * Writes the inventory to an Excel file in the same column order as the
 * supplier sheet the import reads, with the packet barcode as the last
 * column. Every header is one the import already recognises, so a downloaded
 * file can be uploaded again as it is. Pieces and grams are what is on hand
 * now, so the file shows the remaining inventory.
 */
class StoneExportService
{
    /** Header row, in the import's field order. */
    private const HEADERS = [
        'MATERIAL / NAME', 'SHAPE / CUT', 'DIMENSION', 'CARAT', 'TOTAL PIECES', 'PAIRS',
        'WEIGHT (GRAMS)', 'PRICING MODE', 'PRICE PER CARAT', 'PRICE PER PIECE', 'PRICE PER GRAM',
        'TOTAL COST', 'STATUS', 'TRAY NO.', 'IMAGE CODE', 'REMARKS', 'BARCODE',
    ];

    /** Cell text for a figure that does not apply to the lot, as the sheet writes it. */
    private const NONE = 'N/A';

    /**
     * Build the workbook for every lot the query returns.
     *
     * @param  Builder<Stone>  $query
     * @return string Raw .xlsx bytes
     */
    public function build(Builder $query): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Inventory');

        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:Q1')->getFont()->setBold(true);
        $sheet->getStyle('A1:Q1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('9BE5F0');
        $sheet->freezePane('A2');

        $row = 2;
        foreach ($query->cursor() as $stone) {
            foreach ($this->cells($stone) as $index => $value) {
                $coordinate = [$index + 1, $row];

                // Barcode and codes stay text so leading zeros and long digits survive.
                if ($index === 16 && $value !== '') {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
            $row++;
        }

        foreach (range('A', 'Q') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $this->bytes($book);
    }

    /**
     * An empty import sheet: the same headers the import recognises, with
     * three example lots (one per pricing mode) so every column is shown
     * with a value. Barcode is left empty, which makes the import generate one.
     *
     * @return string Raw .xlsx bytes
     */
    public function template(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Import');

        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:Q1')->getFont()->setBold(true);
        $sheet->getStyle('A1:Q1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('9BE5F0');
        $sheet->freezePane('A2');

        $examples = [
            ['Ruby', 'Oval', '8x6', 2.5, 10, 5, 12.5, 'PER CARAT', 150, 375, 12, 3750, 'IN STOCK', 'K1', 'ruby1', 'Sample row - delete before importing', ''],
            ['Citrine', 'Round', '9x7', 1.8, 25, 0, 45, 'PER PIECE', 80, 13.5, 6, 337.5, 'IN STOCK', 'K2', 'citrine1', 'Sample row - delete before importing', ''],
            ['Garnet', 'Cushion', '6x4', 0.9, 40, 10, 120, 'PER GRAM', 60, 9, 18, 2160, 'IN STOCK', 'K3', 'garnet1', 'Sample row - delete before importing', ''],
        ];
        foreach ($examples as $offset => $values) {
            foreach ($values as $index => $value) {
                $sheet->setCellValue([$index + 1, $offset + 2], $value);
            }
        }

        foreach (range('A', 'Q') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $this->bytes($book);
    }

    private function bytes(Spreadsheet $book): string
    {
        $stream = fopen('php://temp', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    /**
     * One lot as a row, matching HEADERS.
     *
     * @return list<string|int|float>
     */
    private function cells(Stone $stone): array
    {
        $mode = $stone->pricing_mode;
        $weighed = $stone->isWeighed();

        return [
            $stone->name,
            $stone->shape ?: self::NONE,
            $stone->dimension ?: self::NONE,
            $stone->carat !== null ? (float) $stone->carat : self::NONE,
            // On hand now; a lot sold by weight keeps grams or carats, not pieces.
            // For a per-carat lot the carat column is the carats on hand.
            $weighed ? self::NONE : (int) $stone->quantity,
            $weighed || ! $stone->pairs ? self::NONE : (int) $stone->pairs,
            $mode === StonePricingMode::PER_GRAM && $stone->weight_grams !== null ? (float) $stone->weight_grams : self::NONE,
            match ($mode) {
                StonePricingMode::PER_CARAT => 'PER CARAT',
                StonePricingMode::PER_PIECE => 'PER PIECE',
                StonePricingMode::PER_GRAM => 'PER GRAM',
                default => self::NONE,
            },
            $stone->price_per_carat !== null ? (float) $stone->price_per_carat : self::NONE,
            $stone->price_per_piece !== null ? (float) $stone->price_per_piece : self::NONE,
            $stone->price_per_gram !== null ? (float) $stone->price_per_gram : self::NONE,
            $stone->total_cost !== null ? (float) $stone->total_cost : self::NONE,
            strtoupper($stone->status->value === 'in_stock' ? 'in stock' : str_replace('_', ' ', $stone->status->value)),
            $stone->tray_no ?: self::NONE,
            $stone->image_code ?: self::NONE,
            $stone->remarks ?: self::NONE,
            (string) ($stone->barcode ?? ''),
        ];
    }
}
