<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * PartyExportService
 *
 * Writes customers or creditors to an Excel file, one row per party, with the
 * same columns for both so the city-wise lists can be shared or filtered further.
 */
class PartyExportService
{
    private const HEADERS = ['CODE', 'NAME', 'PHONE', 'ALTERNATE PHONE', 'EMAIL', 'NIE', 'ADDRESS', 'CITY', 'STATUS'];

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $codeColumn  customer_code or creditor_code
     * @return string Raw .xlsx bytes
     */
    public function build(Builder $query, string $codeColumn): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('List');

        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('9BE5F0');
        $sheet->freezePane('A2');

        $row = 2;
        foreach ($query->cursor() as $party) {
            $cells = [
                $party->{$codeColumn}, $party->name, $party->phone, $party->alternate_phone,
                $party->email, $party->nie, $party->address, $party->city,
                $party->status ? 'Active' : 'Inactive',
            ];
            foreach ($cells as $index => $value) {
                // Everything stays text so phone numbers keep leading zeros and plus signs.
                $sheet->setCellValueExplicit([$index + 1, $row], (string) ($value ?? ''), DataType::TYPE_STRING);
            }
            $row++;
        }

        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $stream = fopen('php://temp', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }
}
