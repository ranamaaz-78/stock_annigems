<?php

namespace App\Services;

use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use App\Support\Imports\HeaderRowFilter;
use App\Support\Imports\XlsxGridReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * StoneImportService
 *
 * Turns a supplier inventory spreadsheet into reviewable stone lots.
 *
 * The import is deliberately three separate steps so the operator stays in
 * control: read the sheet's columns, confirm how they map onto our fields,
 * then check every calculated row before anything is written. Nothing here
 * touches the database — StoneService performs the insert.
 */
class StoneImportService
{
    /**
     * Our fields, in the order they appear in the mapping step.
     *
     * `aliases` are matched against the sheet's header text to preselect a
     * column; `required` marks the fields an import cannot work without.
     *
     * @var array<string, array{label: string, required: bool, aliases: list<string>}>
     */
    public const FIELDS = [
        'name' => ['label' => 'Material / name', 'required' => true, 'aliases' => ['material / name', 'material', 'name', 'stone']],
        'shape' => ['label' => 'Shape / cut', 'required' => false, 'aliases' => ['shape/cut', 'shape', 'cut']],
        'dimension' => ['label' => 'Dimension', 'required' => false, 'aliases' => ['dimension', 'size', 'mm']],
        'carat' => ['label' => 'Carat', 'required' => false, 'aliases' => ['carat', 'ct', 'cts']],
        'pieces' => ['label' => 'Total pieces', 'required' => false, 'aliases' => ['total pieces', 'total pcs', 'pcs', 'pieces', 'qty', 'quantity']],
        'pairs' => ['label' => 'Pairs', 'required' => false, 'aliases' => ['pairs', 'pair']],
        'weight_grams' => ['label' => 'Weight (grams)', 'required' => false, 'aliases' => ['weight (grams)', 'weight in gms / kgs', 'weight in gms', 'weight', 'gms', 'grams']],
        'pricing_mode' => ['label' => 'Pricing mode', 'required' => true, 'aliases' => ['pricing mode', 'measurement', 'pricing', 'price type']],
        'price_per_carat' => ['label' => 'Price per carat', 'required' => false, 'aliases' => ['price per carat', 'price (per ct)', 'price per ct', 'per carat price', 'rate']],
        'price_per_piece' => ['label' => 'Price per piece', 'required' => false, 'aliases' => ['price (per piece)', 'price per piece', 'per piece price']],
        // The sheet keeps the gram rate in the same column as the carat rate,
        // so both default to it and the operator can repoint either one.
        'price_per_gram' => ['label' => 'Price per gram', 'required' => false, 'aliases' => ['price per gram', 'price (per gms)', 'price per gms', 'per gms price', 'price (per ct)']],
        'sheet_total' => ['label' => 'Total Cost', 'required' => false, 'aliases' => ['total cost', 'total', 'amount']],
        'status' => ['label' => 'Status', 'required' => false, 'aliases' => ['status']],
        'tray_no' => ['label' => 'Tray no.', 'required' => false, 'aliases' => ['tray no', 'tray', 'tray number']],
        'image_code' => ['label' => 'Image code', 'required' => false, 'aliases' => ['image code', 'image', 'image name', 'photo', 'picture']],
        'remarks' => ['label' => 'Remarks', 'required' => false, 'aliases' => ['remarks', 'remark', 'notes', 'note']],
    ];

    /** Rows above this are rejected so one file cannot exhaust memory. */
    public const MAX_ROWS = 5000;

    private const DISK = 'stone-imports';

    /**
     * Longest text each column stores. A longer cell is reported on its own
     * row rather than failing the insert for the whole sheet.
     *
     * @var array<string, int>
     */
    private const MAX_LENGTH = [
        'name' => 150,
        'shape' => 60,
        'dimension' => 40,
        'tray_no' => 30,
        'image_code' => 180,
        'remarks' => 255,
    ];

    public function __construct(
        private StonePricingService $pricingService,
        private XlsxGridReader $gridReader,
    ) {}

    /**
     * Stash the upload and read its header row.
     *
     * The file is kept on a private disk under a random token so the mapping
     * and preview steps do not have to re-upload a large workbook.
     *
     * @return array{token: string, filename: string, columns: list<string>, mapping: array<string, int|null>, fields: array<string, array{label: string, required: bool}>, row_count: int}
     */
    public function stage(UploadedFile $file, int $branchId): array
    {
        // An operator who closes the modal leaves their upload behind, so
        // clear out anything stale before adding another one.
        $this->pruneStale($branchId);

        $token = (string) Str::uuid();
        $path = $branchId.'/'.$token.'.'.strtolower($file->getClientOriginalExtension() ?: 'xlsx');

        Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

        try {
            // Only the header row is needed to build the mapping form, and
            // parsing thousands of rows for it makes the upload feel broken.
            [$columns, $rowCount] = $this->readHeader($path);
        } catch (ValidationException $e) {
            // A file we cannot read is of no use to anyone; do not keep it.
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }

        return [
            'token' => $token,
            'filename' => $file->getClientOriginalName(),
            'columns' => $columns,
            'mapping' => $this->autoMap($columns),
            'fields' => $this->fieldList(),
            'row_count' => $rowCount,
        ];
    }

    /**
     * Apply a column mapping and calculate every row.
     *
     * @param  array<string, mixed>  $mapping Field name => column index
     * @return array{rows: list<array<string, mixed>>, ok: int, errors: int}
     */
    public function preview(string $token, array $mapping, int $branchId): array
    {
        $rows = $this->readSheet($this->pathFor($token, $branchId));
        array_shift($rows);

        $mapping = $this->normalizeMapping($mapping);
        $prepared = [];

        foreach ($rows as $index => $cells) {
            $row = $this->mapRow($cells, $mapping);

            // A row with no name at all is a spacer or a trailing blank line.
            if ($row['name'] === '' && $row['pricing_mode'] === '') {
                continue;
            }

            $prepared[] = $this->evaluate($this->cleanNumbers($row), $index + 2);
        }

        return [
            'rows' => $prepared,
            'ok' => count(array_filter($prepared, fn (array $row): bool => $row['issues'] === [])),
            'errors' => count(array_filter($prepared, fn (array $row): bool => $row['issues'] !== [])),
        ];
    }

    /**
     * Recalculate rows the operator edited in the preview grid.
     *
     * Called again before insert so hand-edited values are held to exactly
     * the same rules as the ones read from the sheet.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, ok: int, errors: int}
     */
    public function revalidate(array $rows): array
    {
        $prepared = [];

        foreach ($rows as $row) {
            $prepared[] = $this->evaluate($this->normalizeRow($row), (int) ($row['sheet_row'] ?? 0));
        }

        return [
            'rows' => $prepared,
            'ok' => count(array_filter($prepared, fn (array $row): bool => $row['issues'] === [])),
            'errors' => count(array_filter($prepared, fn (array $row): bool => $row['issues'] !== [])),
        ];
    }

    /**
     * Excel of the rows that could not be imported, ready to fix and upload.
     *
     * Columns follow our field order and carry the sheet's own header text,
     * so the file maps itself again on the next import. A trailing Problem
     * column says what to fix; it is not a field, so a re-import ignores it.
     *
     * @param  list<array<string, mixed>>  $rows Evaluated rows that still have issues
     * @param  array<string, string|null>  $headers Field => original sheet header
     * @return string Raw .xlsx bytes
     */
    public function buildRejectsWorkbook(array $rows, array $headers): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(__('Rows to fix'), 0, 31));

        $fields = array_keys(self::FIELDS);
        $titles = array_map(
            fn (string $field): string => trim((string) ($headers[$field] ?? '')) !== ''
                ? trim((string) $headers[$field])
                : __(self::FIELDS[$field]['label']),
            $fields
        );
        $titles[] = __('Problem');

        // Every value goes in as plain text: a cell starting with = + - or @
        // must never be turned into a formula when the file is opened.
        foreach ($titles as $column => $title) {
            $sheet->setCellValueExplicit([$column + 1, 1], $title, DataType::TYPE_STRING);
        }

        foreach (array_values($rows) as $rowIndex => $row) {
            foreach ($fields as $column => $field) {
                $sheet->setCellValueExplicit(
                    [$column + 1, $rowIndex + 2],
                    $this->exportText($row[$field] ?? ''),
                    DataType::TYPE_STRING
                );
            }

            $sheet->setCellValueExplicit(
                [count($fields) + 1, $rowIndex + 2],
                implode(' ', (array) ($row['issues'] ?? [])),
                DataType::TYPE_STRING
            );
        }

        $sheet->getStyle([1, 1, count($titles), 1])->getFont()->setBold(true);
        foreach (range(1, count($titles)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $stream = fopen('php://temp', 'r+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    /**
     * The rows that were not inserted, laid out exactly like the uploaded sheet.
     *
     * Reads the staged workbook again and copies the original header row and,
     * for every rejected row, all of its original cells in their original
     * columns — including columns we never import, such as a serial number.
     * A value the operator changed in the preview grid replaces the original
     * in its column, so their partial fixes are not thrown away. A Problem
     * column is added at the end; a re-import ignores it.
     *
     * Returns null when the staged file is gone (it expires), so the caller
     * can fall back to buildRejectsWorkbook().
     *
     * @param  list<array<string, mixed>>  $rows Evaluated rows that still have issues
     * @param  array<string, mixed>  $mapping Field => column index chosen in the wizard
     * @return string|null Raw .xlsx bytes
     */
    public function buildRejectsFromSource(array $rows, string $token, array $mapping, int $branchId): ?string
    {
        try {
            $source = $this->readSheet($this->pathFor($token, $branchId));
        } catch (ValidationException) {
            return null;
        }

        $header = $source[0] ?? [];
        $width = count($header);

        // Only columns the operator actually pointed at one of our fields.
        $columns = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $index = $mapping[$field] ?? null;
            if ($index !== null && $index !== '' && (int) $index >= 0) {
                $columns[$field] = (int) $index;
                $width = max($width, (int) $index + 1);
            }
        }

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(__('Rows to fix'), 0, 31));

        // Header row: the sheet's own, then Problem.
        for ($column = 0; $column < $width; $column++) {
            $this->putCell($sheet, $column + 1, 1, (string) ($header[$column] ?? ''), true);
        }
        $this->putCell($sheet, $width + 1, 1, 'Problem', true);

        foreach (array_values($rows) as $position => $row) {
            $cells = $source[((int) ($row['sheet_row'] ?? 0)) - 1] ?? [];

            foreach ($columns as $field => $index) {
                $edited = $this->editedValue($field, (string) ($cells[$index] ?? ''), $row[$field] ?? '');
                if ($edited !== null) {
                    $cells[$index] = $edited;
                }
            }

            for ($column = 0; $column < $width; $column++) {
                $this->putCell($sheet, $column + 1, $position + 2, (string) ($cells[$column] ?? ''));
            }

            $this->putCell($sheet, $width + 1, $position + 2, implode(' ', (array) ($row['issues'] ?? [])));
        }

        foreach (range(1, $width + 1) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
        $sheet->getStyle([1, 1, $width + 1, 1])->getFont()->setBold(true);

        $stream = fopen('php://temp', 'r+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    /**
     * What to write over an original cell, or null to keep the original.
     *
     * The grid shows pricing mode and status in our own words ("per_piece"),
     * so those two are compared after the original is normalised the same
     * way; everything else is compared as plain text.
     */
    private function editedValue(string $field, string $original, mixed $current): ?string
    {
        $current = trim((string) $current);

        $normalisedOriginal = match ($field) {
            'pricing_mode' => (StonePricingMode::tryFrom(trim($original)) ?? StonePricingMode::fromSheet($original))?->value ?? trim($original),
            'status' => (StoneStatus::tryFrom(trim($original)) ?? StoneStatus::fromSheet($original))->value,
            'carat', 'pieces', 'pairs', 'weight_grams', 'price_per_carat', 'price_per_piece', 'price_per_gram' => $this->cleanNumber($original),
            default => trim($original),
        };

        // The sheet's own total is read-only in the grid, so it is never an edit.
        if ($field === 'sheet_total' || $current === $normalisedOriginal) {
            return null;
        }

        return $current;
    }

    /**
     * Write one cell: plain numbers stay numbers, everything else is text so
     * a value starting with = + - or @ can never become a formula.
     */
    private function putCell(Worksheet $sheet, int $column, int $row, string $value, bool $forceText = false): void
    {
        $isNumber = ! $forceText
            && preg_match('/^-?(0|[1-9][0-9]{0,14})(\.[0-9]{1,10})?$/', $value) === 1;

        $sheet->setCellValueExplicit(
            [$column, $row],
            $isNumber ? $value + 0 : $value,
            $isNumber ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING
        );
    }

    /**
     * A cell value as text, with whole numbers kept free of ".0".
     */
    private function exportText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_float($value) ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') : (string) $value;
    }

    /**
     * Drop a staged workbook once the import finishes or is cancelled.
     */
    public function discard(string $token, int $branchId): void
    {
        Storage::disk(self::DISK)->delete($this->pathFor($token, $branchId));
    }

    /**
     * Field metadata for the mapping UI (aliases stay server-side).
     *
     * @return array<string, array{label: string, required: bool}>
     */
    public function fieldList(): array
    {
        $fields = [];
        foreach (self::FIELDS as $key => $meta) {
            $fields[$key] = ['label' => __($meta['label']), 'required' => $meta['required']];
        }

        return $fields;
    }

    /**
     * Read just the header row, plus how many data rows follow it.
     *
     * The mapping step only needs the column names, so a read filter keeps
     * the first row in memory and skips the rest. On a 1700-row workbook
     * this is the difference between a snappy upload and a stalled one.
     *
     * @return array{0: list<string>, 1: int} Header cells, data row count
     */
    private function readHeader(string $path): array
    {
        $absolute = Storage::disk(self::DISK)->path($path);

        // An .xlsx streams fast enough that one pass gives both the header
        // and the row count without PhpSpreadsheet being involved at all.
        if ($this->gridReader->supports($absolute)) {
            $rows = $this->streamRows($absolute);

            if ($rows !== null) {
                return [$rows[0], max(0, count($rows) - 1)];
            }
        }

        // The read filter hides the other rows from the loaded sheet, so the
        // real size has to come from the file's own worksheet metadata.
        $rowCount = max(0, $this->sheetRowCount($path) - 1);

        if ($rowCount > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => __('That sheet has more than :self rows. Split it and import in parts.', ['self' => number_format(self::MAX_ROWS)]),
            ]);
        }

        $sheet = $this->loadSheet($path, headerOnly: true);

        $header = $sheet->rangeToArray(
            'A1:'.$sheet->getHighestDataColumn().'1',
            null,
            true,
            false,
            false
        )[0] ?? [];

        $columns = array_map(fn ($cell): string => trim((string) ($cell ?? '')), $header);

        $this->assertNotEmpty($columns);

        return [$columns, $rowCount];
    }

    /**
     * Read the first worksheet as a plain array of strings.
     *
     * Formulas are resolved to their cached values: the sheet's own totals
     * are only ever compared against ours, never trusted as input.
     *
     * @return list<list<string>>
     */
    private function readSheet(string $path): array
    {
        $absolute = Storage::disk(self::DISK)->path($path);

        if (! is_file($absolute)) {
            throw ValidationException::withMessages([
                'file' => __('That upload has expired. Choose the file again.'),
            ]);
        }

        if ($this->gridReader->supports($absolute)) {
            $rows = $this->streamRows($absolute);

            if ($rows !== null) {
                return $rows;
            }
        }

        return $this->readSheetWithPhpSpreadsheet($path);
    }

    /**
     * Stream an .xlsx workbook, or return null to fall back.
     *
     * A file the fast reader chokes on is still a file the operator expects
     * to import, so a failure here is never surfaced — PhpSpreadsheet gets
     * the second attempt and reports the real problem if there is one.
     *
     * @return list<list<string>>|null
     */
    private function streamRows(string $absolute): ?array
    {
        try {
            $rows = $this->gridReader->rows($absolute, self::MAX_ROWS + 1);
        } catch (RuntimeException $e) {
            // Code 1 is the row limit, which is a real answer, not a failure.
            if ($e->getCode() === 1) {
                throw ValidationException::withMessages([
                    'file' => __('That sheet has more than :self rows. Split it and import in parts.', ['self' => number_format(self::MAX_ROWS)]),
                ]);
            }

            Log::warning('Fast xlsx read failed; falling back to PhpSpreadsheet.', [
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $this->assertNotEmpty($rows[0] ?? []);

        return $rows;
    }

    /**
     * Read the worksheet through PhpSpreadsheet.
     *
     * Used for .xls and .csv, and as the fallback for any .xlsx the streaming
     * reader cannot handle.
     *
     * @return list<list<string>>
     */
    private function readSheetWithPhpSpreadsheet(string $path): array
    {
        $sheet = $this->loadSheet($path);
        $lastColumn = $sheet->getHighestDataColumn();

        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];

            // Every row is read to the same last column so a row that ends
            // early still lines up with the header the operator mapped.
            $iterator = $row->getCellIterator('A', $lastColumn);
            $iterator->setIterateOnlyExistingCells(false);

            foreach ($iterator as $cell) {
                $cells[] = trim((string) ($this->cellValue($cell) ?? ''));
            }

            $rows[] = $cells;

            if (count($rows) > self::MAX_ROWS + 1) {
                throw ValidationException::withMessages([
                    'file' => __('That sheet has more than :self rows. Split it and import in parts.', ['self' => number_format(self::MAX_ROWS)]),
                ]);
            }
        }

        $this->assertNotEmpty($rows[0] ?? []);

        return $rows;
    }

    /**
     * Reject a sheet whose first row holds nothing to map.
     *
     * @param  list<string>  $header
     */
    private function assertNotEmpty(array $header): void
    {
        if ($header === [] || implode('', $header) === '') {
            throw ValidationException::withMessages(['file' => __('That sheet is empty.')]);
        }
    }

    /**
     * Value of one cell, preferring the result the spreadsheet already holds.
     *
     * Supplier sheets total their rows with formulas. Re-running those through
     * the calculation engine costs roughly ten times as much as reading the
     * value Excel cached when it last saved the file, and produces the same
     * numbers. Only a formula with no cached result is calculated here.
     */
    private function cellValue(Cell $cell): mixed
    {
        if ($cell->getDataType() !== DataType::TYPE_FORMULA) {
            return $cell->getValue();
        }

        return $cell->getOldCalculatedValue() ?? $cell->getCalculatedValue();
    }

    /**
     * Total rows in the first worksheet, read from the file's metadata.
     *
     * Cheaper than loading the sheet: nothing is parsed into memory.
     */
    private function sheetRowCount(string $path): int
    {
        $absolute = Storage::disk(self::DISK)->path($path);

        try {
            $info = IOFactory::createReaderForFile($absolute)->listWorksheetInfo($absolute);
        } catch (ReaderException $e) {
            throw ValidationException::withMessages([
                'file' => __('That file could not be read as a spreadsheet.'),
            ]);
        }

        return (int) ($info[0]['totalRows'] ?? 0);
    }

    /**
     * Open the first worksheet of a staged workbook.
     *
     * @param  bool  $headerOnly Load row 1 only, for the mapping step
     */
    private function loadSheet(string $path, bool $headerOnly = false): Worksheet
    {
        $absolute = Storage::disk(self::DISK)->path($path);

        if (! is_file($absolute)) {
            throw ValidationException::withMessages([
                'file' => __('That upload has expired. Choose the file again.'),
            ]);
        }

        try {
            $reader = IOFactory::createReaderForFile($absolute);
            $reader->setReadDataOnly(true);

            if ($headerOnly) {
                $reader->setReadFilter(new HeaderRowFilter);
            }

            return $reader->load($absolute)->getSheet(0);
        } catch (ReaderException $e) {
            throw ValidationException::withMessages([
                'file' => __('That file could not be read as a spreadsheet.'),
            ]);
        }
    }

    /**
     * Preselect a column per field by comparing header text to the aliases.
     *
     * @param  list<string>  $columns
     * @return array<string, int|null>
     */
    private function autoMap(array $columns): array
    {
        $normalized = array_map(
            fn (string $header): string => $this->slug($header),
            $columns
        );

        $mapping = [];
        foreach (self::FIELDS as $field => $meta) {
            $mapping[$field] = null;

            foreach ($meta['aliases'] as $alias) {
                $index = array_search($this->slug($alias), $normalized, true);
                if ($index !== false) {
                    $mapping[$field] = (int) $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Header text reduced to comparable letters and digits.
     */
    private function slug(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }

    /**
     * Keep only known fields and cast the chosen column to an int index.
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, int|null>
     */
    private function normalizeMapping(array $mapping): array
    {
        $clean = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $value = $mapping[$field] ?? null;
            $clean[$field] = ($value === null || $value === '') ? null : (int) $value;
        }

        if ($clean['name'] === null) {
            throw ValidationException::withMessages([
                'mapping.name' => __('Choose the column that holds the stone name.'),
            ]);
        }

        return $clean;
    }

    /**
     * Pull one sheet row into our field names.
     *
     * @param  list<string>  $cells
     * @param  array<string, int|null>  $mapping
     * @return array<string, string>
     */
    private function mapRow(array $cells, array $mapping): array
    {
        $row = [];
        foreach ($mapping as $field => $index) {
            $row[$field] = $index === null ? '' : trim((string) ($cells[$index] ?? ''));
        }

        return $row;
    }

    /**
     * Turn sheet text such as "28.00 €", "1,271.94" or "N/A" into a plain
     * number string; anything that is not a number becomes empty.
     */
    private function cleanNumbers(array $row): array
    {
        foreach (['carat', 'pieces', 'pairs', 'weight_grams', 'price_per_carat', 'price_per_piece', 'price_per_gram', 'sheet_total'] as $field) {
            $row[$field] = $this->cleanNumber((string) ($row[$field] ?? ''));
        }

        return $row;
    }

    private function cleanNumber(string $value): string
    {
        $clean = str_replace(',', '', preg_replace('/[^\d.,\-]/u', '', $value) ?? '');

        return is_numeric($clean) ? $clean : '';
    }

    /**
     * Normalise a row that came back from the browser after editing.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function normalizeRow(array $row): array
    {
        $clean = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $clean[$field] = trim((string) ($row[$field] ?? ''));
        }

        return $clean;
    }

    /**
     * Cost one row and collect everything wrong with it.
     *
     * Returns the row unchanged alongside its verdict, so the preview grid
     * can show the operator's own values back to them for editing.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function evaluate(array $row, int $sheetRow): array
    {
        $issues = [];

        $name = $row['name'];
        if ($name === '') {
            $issues[] = __('Name is missing.');
        }

        // The sheet writes the mode as free text ("PER GMS PRICE"), but an
        // edited row posts back our own value ("per_gram"); accept both.
        $mode = StonePricingMode::tryFrom($row['pricing_mode'])
            ?? StonePricingMode::fromSheet($row['pricing_mode']);

        if ($mode === null) {
            $issues[] = $row['pricing_mode'] === ''
                ? 'Pricing mode is missing.'
                : 'Pricing mode "'.$row['pricing_mode'].'" is not per carat, per piece, or per gram.';
        }

        foreach (self::MAX_LENGTH as $field => $limit) {
            if (mb_strlen($row[$field]) > $limit) {
                $issues[] = __(':field is longer than :limit characters.', ['field' => __(self::FIELDS[$field]['label']), 'limit' => $limit]);
            }
        }

        $status = StoneStatus::tryFrom($row['status']) ?? StoneStatus::fromSheet($row['status']);

        $pricing = $this->pricingService->calculate([
            'pricing_mode' => $mode,
            'carat' => $row['carat'],
            'pieces' => $row['pieces'],
            'pairs' => $row['pairs'],
            'weight_grams' => $row['weight_grams'],
            'price_per_carat' => $row['price_per_carat'],
            'price_per_piece' => $row['price_per_piece'],
            'price_per_gram' => $row['price_per_gram'],
        ]);

        // When the mode itself was rejected the pricing service can only
        // repeat the complaint in vaguer words, so keep the specific one.
        $issues = array_merge($issues, $mode === null ? [] : $pricing['issues']);

        // A mismatch is worth surfacing but is not fatal: the sheet totals
        // some lots differently from our locked formulas, and ours wins.
        $sheetTotal = $row['sheet_total'] === '' ? null : (float) str_replace(',', '', $row['sheet_total']);
        $mismatch = $sheetTotal !== null
            && $issues === []
            && ! $this->pricingService->matches($sheetTotal, $pricing['total_cost']);

        return array_merge($row, [
            'sheet_row' => $sheetRow,
            'pricing_mode' => $mode?->value ?? $row['pricing_mode'],
            'status' => $status->value,
            'piece_price' => $pricing['piece_price'],
            'total_cost' => $pricing['total_cost'],
            'cost_formula' => $pricing['cost_formula'],
            'sheet_total' => $sheetTotal,
            'mismatch' => $mismatch,
            'issues' => array_values($issues),
        ]);
    }

    /**
     * Delete staged workbooks for a branch that are past their retention age.
     *
     * Abandoned uploads are never referenced again — the token only lives in
     * the browser — so keeping them would grow storage without limit.
     */
    private function pruneStale(int $branchId): void
    {
        $disk = Storage::disk(self::DISK);
        $cutoff = now()->subHours(max(1, (int) config('annigems.stones.import_retention_hours', 24)))->getTimestamp();

        foreach ($disk->files((string) $branchId) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Branch-scoped path for a staged workbook.
     */
    private function pathFor(string $token, int $branchId): string
    {
        // A token alone must never be enough to read another branch's upload.
        $files = Storage::disk(self::DISK)->files((string) $branchId);

        foreach ($files as $file) {
            if (str_contains($file, $token)) {
                return $file;
            }
        }

        throw ValidationException::withMessages([
            'file' => __('That upload has expired. Choose the file again.'),
        ]);
    }
}
