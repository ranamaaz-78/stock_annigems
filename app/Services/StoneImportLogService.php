<?php

namespace App\Services;

use App\Models\StoneImport;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * StoneImportLogService
 *
 * Runs an Excel import in batches and keeps its history.
 *
 * An import is three steps: begin (open the record), a number of batches
 * (each one inserts its ready rows in its own transaction), and finish
 * (store the rows that were set aside as an Excel file). Batching is what
 * lets the browser show real progress on a sheet with thousands of rows.
 */
class StoneImportLogService
{
    private const DISK = 'stone-imports';

    public function __construct(
        private StoneImportService $importService,
        private StoneService $stoneService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * Imports for the actor's branch, newest first.
     */
    public function paginate(): LengthAwarePaginator
    {
        return StoneImport::query()
            ->with('creator')
            ->orderByDesc('id')
            ->paginate(per_page())
            ->withQueryString();
    }

    /**
     * Rows per batch the browser should send.
     */
    public function chunkSize(): int
    {
        return max(1, (int) config('annigems.stones.import_chunk_size', 100));
    }

    /**
     * Open the record for an import that is about to start.
     */
    public function begin(User $actor, string $filename, int $totalRows): StoneImport
    {
        return StoneImport::query()->create([
            'branch_id' => $actor->branch_id,
            'filename' => $this->cleanFilename($filename),
            'total_rows' => $totalRows,
            'inserted_rows' => 0,
            'rejected_rows' => 0,
            'chunks_done' => 0,
        ]);
    }

    /**
     * Insert one batch.
     *
     * Rows are re-checked here, so a row that fails now is returned for the
     * rows-to-fix file instead of being inserted. The batch is applied in
     * one transaction together with the counters, and its position must be
     * the next one expected: a batch sent twice cannot insert twice.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, inserted_total: int, rejected: list<array<string, mixed>>}
     */
    public function insertChunk(string $importUuid, int $chunkIndex, array $rows, User $actor): array
    {
        return DB::transaction(function () use ($importUuid, $chunkIndex, $rows, $actor): array {
            $import = $this->lockOpenImport($importUuid);

            if ($chunkIndex !== (int) $import->chunks_done) {
                throw ValidationException::withMessages([
                    'chunk_index' => __('That batch was already received or arrived out of order. Check Import History before trying again.'),
                ]);
            }

            $checked = $this->importService->revalidate($rows);
            $valid = array_values(array_filter($checked['rows'], fn (array $row): bool => $row['issues'] === []));
            $rejected = array_values(array_filter($checked['rows'], fn (array $row): bool => $row['issues'] !== []));

            $created = $valid === [] ? [] : $this->stoneService->registerMany($valid, $actor);

            $import->inserted_rows += count($created);
            $import->chunks_done += 1;
            $import->save();

            return [
                'created' => count($created),
                'inserted_total' => (int) $import->inserted_rows,
                'rejected' => $rejected,
            ];
        });
    }

    /**
     * Close the import and store the rows that were not inserted.
     *
     * @param  list<array<string, mixed>>  $rejectedRows  Rows the operator left red, plus any batch rejects
     * @param  array<string, string|null>  $headers  Field => original sheet header
     * @param  array<string, mixed>  $mapping  Field => column index chosen in the wizard
     * @param  string|null  $token  The staged workbook, so the file can copy the original layout
     */
    public function finish(
        string $importUuid,
        array $rejectedRows,
        array $headers,
        User $actor,
        ?string $token = null,
        array $mapping = [],
    ): StoneImport {
        return DB::transaction(function () use ($importUuid, $rejectedRows, $headers, $actor, $token, $mapping): StoneImport {
            $import = $this->lockOpenImport($importUuid);

            // Issues are worked out again here: the Problem column must say
            // what is wrong now, not what the browser last remembered.
            $rows = $rejectedRows === [] ? [] : $this->importService->revalidate($rejectedRows)['rows'];

            $path = null;
            if ($rows !== []) {
                // A random name keeps the sheet's own name off the disk path;
                // the private branch folder keeps one branch from reaching
                // another's.
                $path = $import->branch_id.'/rejects/'.Str::uuid()->toString().'.xlsx';

                // Same columns, order and values as the uploaded sheet whenever
                // it is still on disk; the field-based layout is the fallback.
                $bytes = $token !== null
                    ? $this->importService->buildRejectsFromSource($rows, $token, $mapping, (int) $import->branch_id)
                    : null;

                Storage::disk(self::DISK)->put(
                    $path,
                    $bytes ?? $this->importService->buildRejectsWorkbook($rows, $headers)
                );
            }

            $import->rejected_rows = count($rows);
            $import->total_rows = $import->inserted_rows + count($rows);
            $import->rejects_path = $path;
            $import->finished_at = now();
            $import->save();

            $this->auditLogService->log('stone_import.created', $import, null, [
                'filename' => $import->filename,
                'total_rows' => $import->total_rows,
                'inserted_rows' => $import->inserted_rows,
                'rejected_rows' => $import->rejected_rows,
            ], $actor);

            return $import;
        });
    }

    /**
     * Absolute path of the rows-to-fix workbook, or null when there is none.
     */
    public function rejectsPath(StoneImport $import): ?string
    {
        if (! $import->hasRejects()) {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        return $disk->exists($import->rejects_path) ? $disk->path($import->rejects_path) : null;
    }

    /**
     * Download name for the rows-to-fix workbook.
     */
    public function rejectsDownloadName(StoneImport $import): string
    {
        $base = pathinfo($import->filename, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '-.');

        return ($base !== '' ? $base : 'stone-import').'-rows-to-fix.xlsx';
    }

    /**
     * The actor's own import that is still open, locked for this request.
     *
     * The branch scope on the model keeps another branch's import out; an
     * unknown or already finished import is reported the same way.
     */
    private function lockOpenImport(string $uuid): StoneImport
    {
        $import = StoneImport::query()->where('uuid', $uuid)->lockForUpdate()->first();

        if ($import === null || $import->finished_at !== null) {
            throw ValidationException::withMessages([
                'import_uuid' => __('This import is not open any more. Start the import again.'),
            ]);
        }

        return $import;
    }

    /**
     * Name as shown in the history: no path, no control characters.
     */
    private function cleanFilename(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return $name !== '' ? mb_substr($name, 0, 255) : 'Spreadsheet';
    }
}
