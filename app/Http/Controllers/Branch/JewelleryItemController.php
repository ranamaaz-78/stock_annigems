<?php

namespace App\Http\Controllers\Branch;

use App\Enums\StonePricingMode;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\LookupJewelleryStoneRequest;
use App\Http\Requests\Branch\StoreJewelleryItemRequest;
use App\Http\Requests\Branch\UpdateJewelleryItemRequest;
use App\Http\Requests\Branch\UpdateJewelleryItemStatusRequest;
use App\Models\Category;
use App\Models\JewelleryItem;
use App\Models\MetalType;
use App\Models\Stone;
use App\Models\SubCategory;
use App\Services\BarcodeService;
use App\Services\JewelleryItemService;
use App\Services\JewelleryQrService;
use App\Services\StoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * JewelleryItemController
 *
 * HTTP for piece list, form, detail, status, stone lookup. Business rules
 * live in JewelleryItemService / JewelleryStoneAllocationService.
 */
class JewelleryItemController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private JewelleryItemService $itemService,
        private StoneService $stoneService,
        private JewelleryQrService $qrService,
        private BarcodeService $barcodeService,
    ) {}

    /**
     * Branch inventory list.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', JewelleryItem::class);

        return view('branch.jewellery-items.index', [
            'items' => $this->itemService->paginate($request->all()),
            'filters' => $request->only([
                'q', 'category_id', 'sub_category_id', 'metal_type_id',
                'purity_id', 'status', 'has_stones', 'weight_min', 'weight_max',
            ]),
            'categories' => Category::query()->ordered()->get(),
            'metals' => MetalType::query()->ordered()->get(),
        ]);
    }

    /**
     * Create form.
     */
    public function create(): View
    {
        $this->authorize('create', JewelleryItem::class);

        return view('branch.jewellery-items.create', $this->formLookups());
    }

    /**
     * Persist a new piece.
     */
    public function store(StoreJewelleryItemRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $item = $this->itemService->create(
                $request->validated(),
                $request->validated('stones') ?? [],
                $request->file('images', []) ?? [],
                $request->user()
            );
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure(collect($e->errors())->flatten()->first() ?? $e->getMessage(), $e->errors());
            }
            throw $e;
        }

        if ($request->expectsJson()) {
            return $this->jsonSuccess(__('Item saved.'), ['uuid' => $item->uuid, 'show_url' => route('branch.jewellery-items.show', $item)], 201);
        }

        return redirect()
            ->route('branch.jewellery-items.show', $item)
            ->with('success', __('Item :code saved.', ['code' => $item->item_code]));
    }

    /**
     * Item detail.
     */
    public function show(JewelleryItem $jewelleryItem): View
    {
        $this->authorize('view', $jewelleryItem);

        $jewelleryItem->load([
            'category', 'subCategory', 'metalType', 'purity',
            'itemStones.stone', 'images', 'movements.performer',
        ]);

        return view('branch.jewellery-items.show', [
            'item' => $jewelleryItem,
            'publicUrl' => $this->qrService->publicUrl($jewelleryItem->qr_token),
            'qrUri' => $this->qrService->dataUri($jewelleryItem->qr_token),
            'barcodeUri' => $this->barcodeService->dataUri($jewelleryItem->item_code),
        ]);
    }

    /**
     * Edit form.
     */
    public function edit(JewelleryItem $jewelleryItem): View
    {
        $this->authorize('update', $jewelleryItem);

        $jewelleryItem->load(['itemStones.stone', 'images']);

        return view('branch.jewellery-items.edit', array_merge($this->formLookups(), [
            'item' => $jewelleryItem,
            'barcodeUri' => $this->barcodeService->dataUri($jewelleryItem->item_code),
            'qrUri' => $this->qrService->dataUri($jewelleryItem->qr_token),
            'publicUrl' => $this->qrService->publicUrl($jewelleryItem->qr_token),
        ]));
    }

    /**
     * Update a piece.
     */
    public function update(UpdateJewelleryItemRequest $request, JewelleryItem $jewelleryItem): RedirectResponse|JsonResponse
    {
        try {
            $item = $this->itemService->update(
                $jewelleryItem,
                $request->validated(),
                $request->validated('stones') ?? [],
                $request->file('images', []) ?? [],
                $request->user(),
                $request->validated('primary_image_id')
            );
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure(collect($e->errors())->flatten()->first() ?? $e->getMessage(), $e->errors());
            }
            throw $e;
        }

        if ($request->expectsJson()) {
            return $this->jsonSuccess(__('Item updated.'), ['uuid' => $item->uuid]);
        }

        return redirect()
            ->route('branch.jewellery-items.show', $item)
            ->with('success', __('Item updated.'));
    }

    /**
     * Soft-delete and return stones.
     */
    public function destroy(JewelleryItem $jewelleryItem): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $jewelleryItem);

        $result = $this->itemService->delete($jewelleryItem, request()->user());

        if (request()->expectsJson()) {
            return $this->jsonSuccess(
                trans_choice('Item deleted. :count stone returned to stock.|Item deleted. :count stones returned to stock.', (int) $result['stones_returned'])
            );
        }

        return redirect()
            ->route('branch.jewellery-items.index')
            ->with('success', trans_choice('Item deleted. :count stone returned to stock.|Item deleted. :count stones returned to stock.', (int) $result['stones_returned']));
    }

    /**
     * Change status.
     */
    public function updateStatus(UpdateJewelleryItemStatusRequest $request, JewelleryItem $jewelleryItem): RedirectResponse|JsonResponse
    {
        $item = $this->itemService->changeStatus(
            $jewelleryItem,
            \App\Enums\JewelleryItemStatus::from($request->validated('status')),
            $request->user(),
            $request->validated('notes')
        );

        if ($request->expectsJson()) {
            return $this->jsonSuccess(__('Status updated.'));
        }

        return redirect()->route('branch.jewellery-items.show', $item)->with('success', __('Status updated.'));
    }

    /**
     * Lookup a stone for allocation (jewellery permission, not stones.view).
     */
    public function lookupStone(LookupJewelleryStoneRequest $request): JsonResponse
    {
        $result = $this->stoneService->lookupScan(
            $request->validated('code'),
            (int) $request->user()->branch_id
        );

        return match ($result['status']) {
            'found' => $this->jsonSuccess(__('Found.'), [
                'stone' => $this->stonePayload($result['stone']),
            ]),
            'inactive' => $this->jsonFailure(__('This stone is deactivated.'), ['code' => ['This stone is deactivated.']], 422),
            default => $this->jsonFailure(__('Barcode not found.'), ['code' => ['Barcode not found.']], 404),
        };
    }

    /**
     * Search stones by name/code for allocation.
     */
    public function searchStones(Request $request): JsonResponse
    {
        $this->authorizeJewelleryForm();

        $stones = $this->stoneService->searchActive((string) $request->query('q', ''));

        return $this->jsonSuccess(__('OK.'), [
            'stones' => $stones->map(fn ($stone) => $this->stonePayload($stone))->values(),
        ]);
    }

    /**
     * Stone as the item form needs it: the scan payload plus, for users who
     * may see cost, the lot's rate so the form can show the price as stones
     * are added. The server prices the allocation again on save.
     *
     * @return array<string, mixed>
     */
    private function stonePayload(Stone $stone): array
    {
        $payload = $this->stoneService->toScanPayload($stone);

        if (! request()->user()?->hasPermission('items.view_cost')) {
            return $payload;
        }

        return array_merge($payload, [
            'show_cost' => true,
            'rate' => $stone->rate() === null ? null : (string) $stone->rate(),
            'rate_unit' => match ($stone->pricing_mode) {
                StonePricingMode::PER_CARAT => 'ct',
                StonePricingMode::PER_GRAM => 'g',
                default => 'pc',
            },
        ]);
    }

    /**
     * Sub-categories for a category (item form).
     */
    public function subCategories(Category $category): JsonResponse
    {
        $this->authorizeJewelleryForm();

        $rows = SubCategory::query()
            ->active()
            ->where('category_id', $category->id)
            ->ordered()
            ->get()
            ->map(fn (SubCategory $sub): array => [
                'id' => $sub->id,
                'uuid' => $sub->uuid,
                'name' => $sub->name,
            ]);

        return $this->jsonSuccess(__('OK.'), ['sub_categories' => $rows]);
    }

    /**
     * Shared form dictionaries.
     *
     * @return array<string, mixed>
     */
    private function formLookups(): array
    {
        return [
            'categories' => Category::query()->active()->ordered()->get(),
            'metals' => MetalType::query()->active()->ordered()->get(),
            'scanner' => scanner_config(),
            'gramsPerCarat' => \App\Services\JewelleryWeightService::GRAMS_PER_CARAT,
            'statuses' => \App\Enums\JewelleryItemStatus::usableInThisModule(),
        ];
    }

    /**
     * Stone allocation and catalog lookups on the item form use jewellery
     * create/edit, not stones.view.
     */
    private function authorizeJewelleryForm(): void
    {
        abort_unless(
            request()->user()?->can('create', JewelleryItem::class)
            || request()->user()?->hasPermission('jewellery.edit'),
            403
        );
    }
}
