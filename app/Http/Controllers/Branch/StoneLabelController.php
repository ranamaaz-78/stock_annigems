<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoneLabelDataRequest;
use App\Models\Stone;
use App\Services\StoneLabelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * StoneLabelController
 *
 * A4 barcode sheets — one stone per page, identical barcodes in a grid.
 */
class StoneLabelController extends Controller
{
    use RespondsWithJson;

    public function __construct(private StoneLabelService $labelService) {}

    /**
     * Label data for the print dialog: each stone's name, codes, price and a
     * vector barcode, in the order the operator chose them.
     */
    public function data(StoneLabelDataRequest $request): JsonResponse
    {
        $this->authorize('printLabel', Stone::class);

        $stones = $this->labelService->stonesForUuids((array) $request->validated('uuids'));

        foreach ($stones as $stone) {
            // A stone from another branch (or one without a barcode) is refused here.
            abort_unless($request->user()->can('printLabel', $stone) || ! $stone->hasPacketBarcode(), 403);
        }

        return $this->jsonSuccess(__('Labels ready.'), $this->labelService->dialogData($stones));
    }

    /**
     * One stone, repeated across the sheet.
     */
    public function show(Request $request, Stone $stone): View
    {
        $this->authorize('printLabel', $stone);
        abort_unless($stone->hasPacketBarcode(), 404);

        $stone->load(['branch']);

        return view(
            'shared.labels.sheet',
            $this->labelService->printView(collect([$stone]), $request->integer('copies')),
        );
    }

    /**
     * Several stones: each gets its own page of repeated barcodes.
     */
    public function bulk(Request $request): View
    {
        $this->authorize('printLabel', Stone::class);

        $uuids = array_values(array_filter((array) $request->input('uuids', [])));
        abort_if($uuids === [], 404);

        $stones = $this->labelService->stonesForUuids($uuids)
            ->filter(fn (Stone $stone): bool => $stone->hasPacketBarcode())
            ->values();
        abort_if($stones->isEmpty(), 404);

        foreach ($stones as $stone) {
            $this->authorize('printLabel', $stone);
        }

        return view(
            'shared.labels.sheet',
            $this->labelService->printView($stones, $request->integer('copies')),
        );
    }
}
