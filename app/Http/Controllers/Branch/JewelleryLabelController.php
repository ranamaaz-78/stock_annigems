<?php

namespace App\Http\Controllers\Branch;

use App\Enums\LabelKind;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\JewelleryItem;
use App\Services\JewelleryLabelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * JewelleryLabelController
 *
 * Barcode, QR, and both are separate print jobs. Each unique piece prints once;
 * a bulk selection packs every chosen piece onto the same A4 grid.
 */
class JewelleryLabelController extends Controller
{
    use RespondsWithJson;

    public function __construct(private JewelleryLabelService $labelService) {}

    /**
     * Label data for the shared print dialog (barcode labels only).
     */
    public function data(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('jewellery.barcode'), 403);

        $uuids = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:300'],
            'uuids.*' => ['required', 'uuid'],
            'kind' => ['nullable', 'in:barcode,qr,both'],
        ]);

        $kind = LabelKind::tryFrom((string) ($request->input('kind') ?? 'barcode')) ?? LabelKind::BARCODE;
        $uuids = $request->input('uuids');

        $items = $this->labelService->itemsForUuids($uuids);

        foreach ($items as $item) {
            $this->authorize('printLabel', $item);
        }

        return $this->jsonSuccess(__('Labels ready.'), $this->labelService->dialogData($items, $kind));
    }

    /**
     * Bookmarks of the old combined label URL open the barcode sheet.
     */
    public function legacy(JewelleryItem $jewelleryItem): RedirectResponse
    {
        $this->authorize('printLabel', $jewelleryItem);

        return redirect()->route('branch.jewellery-items.label', [$jewelleryItem, LabelKind::BARCODE]);
    }

    /**
     * One piece — barcode, QR, or both on a single cell.
     */
    public function show(JewelleryItem $jewelleryItem, LabelKind $kind): View
    {
        $this->authorize('printLabel', $jewelleryItem);
        $jewelleryItem->load(['branch', 'metalType', 'purity']);

        $payload = $this->labelService->printView(collect([$jewelleryItem]), $kind);
        abort_if($payload['sheets'] === [], 404);

        return view('shared.labels.sheet', $payload);
    }

    /**
     * Selected pieces share one grid — each unique code prints once.
     */
    public function bulk(Request $request, LabelKind $kind): View
    {
        abort_unless($request->user()?->hasPermission('jewellery.barcode'), 403);

        $uuids = array_values(array_filter((array) $request->input('uuids', [])));
        abort_if($uuids === [], 404);

        $items = $this->labelService->itemsForUuids($uuids);
        abort_if($items->isEmpty(), 404);

        foreach ($items as $item) {
            $this->authorize('printLabel', $item);
        }

        $payload = $this->labelService->printView($items, $kind);
        abort_if($payload['sheets'] === [], 404);

        return view('shared.labels.sheet', $payload);
    }
}
