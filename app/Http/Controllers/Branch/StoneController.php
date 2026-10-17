<?php

namespace App\Http\Controllers\Branch;

use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreStoneRequest;
use App\Http\Requests\Branch\UpdateStoneRequest;
use App\Models\Stone;
use App\Models\StoneCategory;
use App\Services\StoneCategoryService;
use App\Services\StoneLabelService;
use App\Services\StoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * StoneController
 *
 * HTTP for stone lots: list, register, detail, edit, deactivate. Pricing
 * and stock rules live in StoneService and StonePricingService.
 */
class StoneController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private StoneService $stoneService,
        private StoneLabelService $labelService,
        private StoneCategoryService $categoryService,
    ) {}

    /**
     * Branch stone list.
     *
     * Opens on the material folders. Choosing one — or searching with any
     * other filter applied — drops into the lots themselves, because a
     * folder tile cannot express "per carat, tray D1, out of stock".
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Stone::class);

        $filters = $request->only([
            'q', 'category', 'pricing_mode', 'status', 'tray_no', 'shape',
            'is_active', 'stock', 'carat_min', 'carat_max',
        ]);

        $category = $this->openFolder($request);

        if ($category === null && ! $this->hasLotFilters($filters)) {
            return view('branch.stones.index', array_merge($this->formLookups(), [
                'categories' => $this->categoryService->paginate($filters),
                'filters' => $filters,
                'category' => null,
                'stones' => null,
            ]));
        }

        return view('branch.stones.index', array_merge($this->formLookups(), [
            'categories' => null,
            'category' => $category,
            'stones' => $this->stoneService->paginate($filters),
            'filters' => $filters,
        ]));
    }

    /**
     * Registration form.
     */
    public function create(): View
    {
        $this->authorize('create', Stone::class);

        return view('branch.stones.create', $this->formLookups());
    }

    /**
     * Persist a new lot. Money and opening stock are written by the service.
     */
    public function store(StoreStoneRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $stone = $this->stoneService->register($request->validated(), $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
            }

            throw $e;
        }

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Stone registered.', [
                'stone' => $this->stoneService->toScanPayload($stone),
                'show_url' => route('branch.stones.show', $stone),
            ], 201);
        }

        return redirect()
            ->route('branch.stones.show', $stone)
            ->with('success', 'Stone registered. Print a packet label next.');
    }

    /**
     * Stone detail with movement ledger.
     */
    public function show(Stone $stone): View
    {
        $this->authorize('view', $stone);

        $stone->load([
            'creator', 'updater', 'branch', 'image',
            'stockMovements' => fn ($q) => $q->with(['creator', 'reference'])->latest('id')->limit(100),
        ]);

        return view('branch.stones.show', [
            'stone' => $stone,
            'barcodeUri' => $this->labelService->barcodeDataUri($stone),
        ]);
    }

    /**
     * Edit form.
     */
    public function edit(Stone $stone): View
    {
        $this->authorize('update', $stone);

        return view('branch.stones.edit', array_merge($this->formLookups(), [
            'stone' => $stone,
        ]));
    }

    /**
     * Update lot details. Quantity moves through the ledger, not this form.
     */
    public function update(UpdateStoneRequest $request, Stone $stone): RedirectResponse
    {
        $this->stoneService->update($stone, $request->validated(), $request->user());

        return redirect()
            ->route('branch.stones.show', $stone)
            ->with('success', 'Stone updated.');
    }

    /**
     * Deactivate so it cannot be intaken or sold.
     */
    public function deactivate(Stone $stone): RedirectResponse
    {
        $this->authorize('deactivate', $stone);
        $this->stoneService->deactivate($stone, request()->user());

        return redirect()
            ->route('branch.stones.show', $stone)
            ->with('success', 'Stone deactivated. Existing stock is unchanged.');
    }

    /**
     * Delete a lot that no open document needs. Remaining stock is written off.
     */
    public function destroy(Stone $stone): RedirectResponse
    {
        $this->authorize('delete', $stone);

        try {
            $result = $this->stoneService->delete($stone, request()->user());
        } catch (ValidationException $e) {
            return redirect()
                ->route('branch.stones.show', $stone)
                ->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()
            ->route('branch.stones.index')
            ->with('success', $stone->stone_code.' deleted.'.($result['written_off'] !== '0' ? ' '.$result['written_off'].' written off.' : ''));
    }

    /**
     * Reactivate.
     */
    public function activate(Stone $stone): RedirectResponse
    {
        $this->authorize('deactivate', $stone);
        $this->stoneService->activate($stone, request()->user());

        return redirect()
            ->route('branch.stones.show', $stone)
            ->with('success', 'Stone reactivated.');
    }

    /**
     * Name search for intake add-by-name.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('create', \App\Models\StoneIntake::class);

        $stones = $this->stoneService->searchActive((string) $request->query('q', ''));

        return $this->jsonSuccess('ok', [
            'stones' => $stones->map(fn (Stone $stone) => $this->stoneService->toScanPayload($stone))->values(),
        ]);
    }

    /**
     * The folder being viewed, or null when none was requested.
     *
     * An unknown uuid is treated as no folder rather than a 404: the
     * operator simply lands back on the folder grid.
     */
    private function openFolder(Request $request): ?StoneCategory
    {
        $uuid = trim((string) $request->query('category', ''));

        if ($uuid === '') {
            return null;
        }

        return StoneCategory::query()->where('uuid', $uuid)->first();
    }

    /**
     * Whether any filter narrows individual lots rather than folders.
     *
     * @param  array<string, mixed>  $filters
     */
    private function hasLotFilters(array $filters): bool
    {
        foreach (['pricing_mode', 'status', 'tray_no', 'shape', 'is_active', 'stock', 'carat_min', 'carat_max'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Shared option lists for the list filters and the create/edit forms.
     *
     * Trays and shapes are free text on the sheet, so the dropdowns are
     * built from what this branch has actually recorded.
     *
     * @return array<string, mixed>
     */
    private function formLookups(): array
    {
        return [
            'pricingModes' => StonePricingMode::options(),
            'statuses' => StoneStatus::options(),
            'trays' => Stone::query()
                ->whereNotNull('tray_no')
                ->distinct()
                ->orderBy('tray_no')
                ->pluck('tray_no')
                ->all(),
            'shapes' => Stone::query()
                ->whereNotNull('shape')
                ->distinct()
                ->orderBy('shape')
                ->pluck('shape')
                ->all(),
        ];
    }
}
