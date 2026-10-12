<?php

namespace App\Support\Imports;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * HeaderRowFilter
 *
 * Restricts a PhpSpreadsheet read to row 1.
 *
 * The Excel import asks the operator to confirm the column mapping before
 * anything is calculated, and that step only needs the header text. Without
 * this filter the whole workbook is parsed into memory just to read one row,
 * which on a supplier sheet of a few thousand lots takes long enough that the
 * upload looks like it has hung.
 */
class HeaderRowFilter implements IReadFilter
{
    /**
     * Whether a cell should be loaded.
     *
     * @param  string  $columnAddress Column letter, e.g. "C"
     * @param  int  $row 1-based row number
     * @param  string  $worksheetName Unused; the filter applies to every sheet
     */
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row === 1;
    }
}
