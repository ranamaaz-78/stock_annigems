<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\Stone;
use App\Services\StoneExportService;
use App\Services\StoneService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
            'Content-Disposition' => $this->disposition($query),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * "<stone name> - <company> - <date>.xlsx" when the file holds one folder or
     * stone name, "Inventory - <company> - <date>.xlsx" when it holds several.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Stone>  $query
     */
    private function disposition($query): string
    {
        $names = (clone $query)->reorder()->distinct()->limit(2)->pluck('name');

        $subject = $names->count() === 1 ? trim((string) $names->first()) : '';
        $subject = $subject !== '' ? $subject : __('Inventory');

        $filename = sprintf('%s - %s - %s.xlsx', $subject, setting('company_name', 'Aani Gemas'), now()->format('Y-m-d'));
        // No path characters or control characters in a download name.
        $filename = trim((string) preg_replace('/\s+/u', ' ', str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], ' ', $filename)));

        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            Str::ascii($filename)
        );
    }
}
