<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreMetalTypeRequest;
use App\Http\Requests\Branch\StorePurityRequest;
use App\Models\Category;
use App\Models\JewelleryItem;
use App\Models\MetalType;
use App\Models\Purity;
use App\Services\MetalCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * MetalTypeController
 *
 * Tabbed CRUD for global metals and purities (same pattern as stone attributes).
 */
class MetalTypeController extends Controller
{
    use RespondsWithJson;

    public function __construct(private MetalCatalogService $catalogService) {}

    /**
     * Metals / purities workspace.
     */
    public function index(): View
    {
        $this->authorize('viewAny', Category::class);

        return view('branch.metals.index', [
            'metals' => $this->catalogService->listMetals(),
            'purities' => $this->catalogService->listPurities(),
            'canManage' => auth()->user()?->can('manage', Category::class) ?? false,
        ]);
    }

    /**
     * Active purities for the item form (filtered by metal).
     */
    public function purities(MetalType $metalType): JsonResponse
    {
        abort_unless(
            request()->user()?->can('viewAny', Category::class)
            || request()->user()?->can('create', JewelleryItem::class)
            || request()->user()?->hasPermission('jewellery.edit'),
            403
        );

        $rows = $this->catalogService->puritiesForMetal((int) $metalType->id)->map(fn (Purity $purity): array => [
            'id' => $purity->id,
            'uuid' => $purity->uuid,
            'name' => $purity->name,
            'value' => (string) $purity->value,
        ]);

        return $this->jsonSuccess('OK.', ['purities' => $rows]);
    }

    /**
     * Create a metal type.
     */
    public function storeMetal(StoreMetalTypeRequest $request): RedirectResponse|JsonResponse
    {
        $row = $this->catalogService->createMetal($request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Metal saved.', ['uuid' => $row->uuid], 201);
        }

        return redirect()->route('branch.metals.index')->with('success', 'Metal saved.');
    }

    /**
     * Update a metal type.
     */
    public function updateMetal(StoreMetalTypeRequest $request, MetalType $metalType): RedirectResponse|JsonResponse
    {
        $this->catalogService->updateMetal($metalType, $request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Metal updated.');
        }

        return redirect()->route('branch.metals.index')->with('success', 'Metal updated.');
    }

    /**
     * Soft-delete a metal when unused.
     */
    public function destroyMetal(MetalType $metalType): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', Category::class);

        try {
            $this->catalogService->deleteMetal($metalType, request()->user());
        } catch (ValidationException $e) {
            if (request()->expectsJson()) {
                return $this->jsonFailure(collect($e->errors())->flatten()->first() ?? $e->getMessage(), $e->errors());
            }
            throw $e;
        }

        if (request()->expectsJson()) {
            return $this->jsonSuccess('Metal deleted.');
        }

        return redirect()->route('branch.metals.index')->with('success', 'Metal deleted.');
    }

    /**
     * Create a purity.
     */
    public function storePurity(StorePurityRequest $request): RedirectResponse|JsonResponse
    {
        $row = $this->catalogService->createPurity($request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Purity saved.', ['uuid' => $row->uuid], 201);
        }

        return redirect()->route('branch.metals.index', ['tab' => 'purities'])->with('success', 'Purity saved.');
    }

    /**
     * Update a purity.
     */
    public function updatePurity(StorePurityRequest $request, Purity $purity): RedirectResponse|JsonResponse
    {
        $this->catalogService->updatePurity($purity, $request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Purity updated.');
        }

        return redirect()->route('branch.metals.index', ['tab' => 'purities'])->with('success', 'Purity updated.');
    }

    /**
     * Soft-delete a purity when unused.
     */
    public function destroyPurity(Purity $purity): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', Category::class);

        try {
            $this->catalogService->deletePurity($purity, request()->user());
        } catch (ValidationException $e) {
            if (request()->expectsJson()) {
                return $this->jsonFailure(collect($e->errors())->flatten()->first() ?? $e->getMessage(), $e->errors());
            }
            throw $e;
        }

        if (request()->expectsJson()) {
            return $this->jsonSuccess('Purity deleted.');
        }

        return redirect()->route('branch.metals.index', ['tab' => 'purities'])->with('success', 'Purity deleted.');
    }
}
