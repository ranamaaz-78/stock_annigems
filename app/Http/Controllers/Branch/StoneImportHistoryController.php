<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\StoneImport;
use App\Services\StoneImportLogService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * StoneImportHistoryController
 *
 * The list of Excel imports and the download of the rows each one set aside.
 */
class StoneImportHistoryController extends Controller
{
    public function __construct(private StoneImportLogService $logService) {}

    /**
     * Imports for the branch, newest first.
     */
    public function index(): View
    {
        $this->authorize('viewAny', StoneImport::class);

        return view('branch.stone-imports.index', [
            'imports' => $this->logService->paginate(),
        ]);
    }

    /**
     * Download the rows that were not inserted, as an Excel file.
     */
    public function rejects(StoneImport $stoneImport): BinaryFileResponse
    {
        $this->authorize('view', $stoneImport);

        $path = $this->logService->rejectsPath($stoneImport);
        abort_unless($path !== null, 404);

        return response()->download($path, $this->logService->rejectsDownloadName($stoneImport), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
