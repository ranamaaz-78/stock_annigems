<?php

namespace App\Http\Controllers;

use App\Models\JewelleryImage;
use App\Services\JewelleryImageService;
use App\Services\PublicJewelleryItemService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\View\View;

/**
 * PublicJewelleryItemController
 *
 * Unauthenticated product page addressed by qr_token. Invalid tokens are a
 * branded 404. Unavailable statuses show a generic message only.
 */
class PublicJewelleryItemController extends Controller
{
    public function __construct(
        private PublicJewelleryItemService $publicService,
        private JewelleryImageService $imageService,
    ) {}

    /**
     * Public product page.
     */
    public function show(string $qrToken): View
    {
        $item = $this->publicService->findByToken($qrToken);
        if (! $item) {
            throw new NotFoundHttpException;
        }

        $visible = $this->publicService->isPubliclyVisible($item);

        return view('public.jewellery-item', [
            'item' => $item,
            'visible' => $visible,
            'payload' => $visible ? $this->publicService->publicPayload($item) : null,
            'qrToken' => $qrToken,
        ]);
    }

    /**
     * Stream a photo only when the piece is publicly visible.
     *
     * $photo is a 0-based index in the ordered gallery, not the database id.
     */
    public function image(string $qrToken, int $photo): BinaryFileResponse
    {
        $item = $this->publicService->findByToken($qrToken);
        if (! $item || ! $this->publicService->isPubliclyVisible($item)) {
            throw new NotFoundHttpException;
        }

        $image = $item->images->values()->get($photo);
        abort_unless($image instanceof JewelleryImage, 404);

        $path = $this->imageService->absolutePath($image);
        abort_unless($path, 404);

        return response()->file($path);
    }
}
