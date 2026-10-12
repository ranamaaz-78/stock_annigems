<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\PreviewStoneImportRequest;
use App\Http\Requests\Branch\StageStoneImportRequest;
use App\Http\Requests\Branch\StoneImportRunRequest;
use App\Http\Requests\Branch\StoreStoneImportRequest;
use App\Models\Stone;
use App\Services\StoneImportLogService;
use App\Services\StoneImportService;
use App\Services\StoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * StoneImportController
 *
 * AJAX endpoints behind the Upload Excel modal, one per wizard step:
 * stage the file, preview the mapped rows, then insert them in batches
 * (begin, chunk, finish).
 *
 * Every response uses the project envelope so the modal can render errors
 * inline instead of showing a raw failure.
 */
class StoneImportController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private StoneImportService $importService,
        private StoneService $stoneService,
        private StoneImportLogService $logService,
    ) {}

    /**
     * Step 1 — accept the workbook and return its columns with a suggested
     * mapping the operator can correct.
     */
    public function stage(StageStoneImportRequest $request): JsonResponse
    {
        try {
            $staged = $this->importService->stage(
                $request->file('file'),
                (int) $request->user()->branch_id
            );
        } catch (ValidationException $e) {
            return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
        }

        return $this->jsonSuccess('Spreadsheet read.', $staged);
    }

    /**
     * Step 2 — apply the confirmed mapping and cost every row.
     */
    public function preview(PreviewStoneImportRequest $request): JsonResponse
    {
        try {
            $preview = $this->importService->preview(
                (string) $request->input('token'),
                (array) $request->input('mapping', []),
                (int) $request->user()->branch_id
            );
        } catch (ValidationException $e) {
            return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
        }

        return $this->jsonSuccess('Rows processed.', $preview);
    }

    /**
     * Re-cost rows the operator edited in the preview grid.
     *
     * Uses exactly the rules the insert step uses, so a row only turns green
     * once it would genuinely be accepted.
     */
    public function recheck(StoreStoneImportRequest $request): JsonResponse
    {
        return $this->jsonSuccess(
            'Rows rechecked.',
            $this->importService->revalidate((array) $request->input('rows', []))
        );
    }

    /**
     * Step 3a — open the import record and tell the browser the batch size.
     *
     * Insert runs as begin, batches, finish so a large sheet can show real
     * progress instead of one long wait.
     */
    public function begin(StoneImportRunRequest $request): JsonResponse
    {
        $this->authorize('create', Stone::class);

        $import = $this->logService->begin(
            $request->user(),
            (string) $request->input('filename', ''),
            (int) $request->input('total_rows')
        );

        return $this->jsonSuccess('Import started.', [
            'import_uuid' => $import->uuid,
            'chunk_size' => $this->logService->chunkSize(),
        ], 201);
    }

    /**
     * Step 3b — insert one batch of rows. Rows that fail the final check are
     * returned so the browser can add them to the rows-to-fix file.
     */
    public function chunk(StoneImportRunRequest $request): JsonResponse
    {
        $this->authorize('create', Stone::class);

        try {
            $result = $this->logService->insertChunk(
                (string) $request->input('import_uuid'),
                (int) $request->input('chunk_index'),
                (array) $request->input('rows', []),
                $request->user()
            );
        } catch (ValidationException $e) {
            return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
        }

        return $this->jsonSuccess('Batch inserted.', $result);
    }

    /**
     * Step 3c — close the import and keep the rows that were not inserted as
     * an Excel file in the sheet's own columns, to fix and import again.
     */
    public function finish(StoneImportRunRequest $request): JsonResponse
    {
        $this->authorize('create', Stone::class);

        try {
            $import = $this->logService->finish(
                (string) $request->input('import_uuid'),
                (array) $request->input('rejected_rows', []),
                (array) $request->input('headers', []),
                $request->user(),
                $request->filled('token') ? (string) $request->input('token') : null,
                (array) $request->input('mapping', [])
            );
        } catch (ValidationException $e) {
            return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
        }

        if ($request->filled('token')) {
            $this->importService->discard(
                (string) $request->input('token'),
                (int) $request->user()->branch_id
            );
        }

        $message = $import->inserted_rows.' rows inserted.';
        if ($import->rejected_rows > 0) {
            $message .= ' '.$import->rejected_rows.' rows were not inserted. Download them from Stones > Import History.';
        }

        return $this->jsonSuccess($message, [
            'inserted' => (int) $import->inserted_rows,
            'rejected' => (int) $import->rejected_rows,
            'import_uuid' => $import->uuid,
        ]);
    }
}
