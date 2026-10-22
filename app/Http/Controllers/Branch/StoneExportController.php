<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\Stone;
use App\Services\StoneExportService;
use App\Services\StoneService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * StoneExportController
 *
 * Downloads the inventory as Excel: everything that matches the page's
 * filters, the ticked folders, or the ticked lots.
 */
class StoneExportController extends Controller
{
    public function __construct(
        private StoneService $stoneService,
        private StoneExportService $exportService,
    ) {}

    public function download(Request $request): Response
    {
        $this->authorize('viewAny', Stone::class);

        $data = $request->validate([
            'categories' => ['nullable', 'array', 'max:500'],
            'categories.*' => ['string', 'max:64'],
            'uuids' => ['nullable', 'array', 'max:5000'],
            'uuids.*' => ['string', 'max:64'],
        ]);

        $filters = $request->only([
            'q', 'category', 'pricing_mode', 'status', 'tray_no', 'shape',
            'is_active', 'stock', 'carat', 'carat_min', 'carat_max',
        ]);

        $query = $this->stoneService->exportQuery($filters, $data['categories'] ?? [], $data['uuids'] ?? []);

        return response($this->exportService->build($query), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="stone-inventory-'.now()->format('Y-m-d').'.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
