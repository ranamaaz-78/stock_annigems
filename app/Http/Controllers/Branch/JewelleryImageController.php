<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\JewelleryImage;
use App\Models\JewelleryItem;
use App\Services\JewelleryImageService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * JewelleryImageController
 *
 * Authenticated streaming of private-disk photos.
 */
class JewelleryImageController extends Controller
{
    public function __construct(private JewelleryImageService $imageService) {}

    /**
     * Stream one photo to a branch user who can view the piece.
     */
    public function show(JewelleryItem $jewelleryItem, JewelleryImage $image): BinaryFileResponse
    {
        $this->authorize('view', $jewelleryItem);
        abort_unless((int) $image->jewellery_item_id === (int) $jewelleryItem->id, 404);

        $path = $this->imageService->absolutePath($image);
        abort_unless($path, 404);

        return response()->file($path);
    }

    /**
     * Remove one photo.
     */
    public function destroy(JewelleryItem $jewelleryItem, JewelleryImage $image): RedirectResponse
    {
        $this->authorize('update', $jewelleryItem);
        abort_unless((int) $image->jewellery_item_id === (int) $jewelleryItem->id, 404);

        $this->imageService->delete($jewelleryItem, $image);

        return redirect()
            ->route('branch.jewellery-items.edit', $jewelleryItem)
            ->with('success', __('Image removed.'));
    }
}
