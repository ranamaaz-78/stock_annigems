<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\CheckStoneImageNamesRequest;
use App\Http\Requests\Branch\RenameStoneImageRequest;
use App\Http\Requests\Branch\StoreStoneImagesRequest;
use App\Models\StoneImage;
use App\Services\StoneImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * StoneImageController
 *
 * HTTP for the Stones image folder. Storage, unique names, and rename
 * rules live in StoneImageService.
 */
class StoneImageController extends Controller
{
    use RespondsWithJson;

    public function __construct(private StoneImageService $imageService) {}

    /**
     * Folder screen: tiles plus the upload drop zone.
     */
    public function index(): View
    {
        $this->authorize('viewAny', StoneImage::class);

        $images = $this->imageService->list()
            ->map(fn (StoneImage $image): array => $this->imageService->payload($image))
            ->values();

        return view('branch.stone-images.index', [
            'images' => $images,
        ]);
    }

    /**
     * Stream one picture to an authorized branch user.
     */
    public function show(StoneImage $stoneImage): BinaryFileResponse
    {
        $this->authorize('view', $stoneImage);

        $path = $this->imageService->absolutePath($stoneImage);
        abort_unless($path !== null, 404);

        $filename = str_replace(['"', "\r", "\n"], '', $stoneImage->name);

        return response()->file($path, [
            'Content-Type' => $stoneImage->mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Which of the names about to be uploaded are already in the folder.
     */
    public function check(CheckStoneImageNamesRequest $request): JsonResponse
    {
        $this->authorize('create', StoneImage::class);

        return $this->jsonSuccess(__('Checked.'), [
            'existing' => $this->imageService->existingNames(
                (array) $request->validated('names'),
                (int) $request->user()->branch_id
            ),
        ]);
    }

    /**
     * Accept a drop or a file-picker batch.
     *
     * `on_duplicate` (skip | replace) says what to do with a name that is
     * already in the folder; without it such a file is reported and left out.
     */
    public function store(StoreStoneImagesRequest $request): JsonResponse
    {
        $this->authorize('create', StoneImage::class);

        $result = $this->imageService->storeMany(
            $request->file('files', []),
            $request->user(),
            $request->validated('on_duplicate')
        );

        $payloads = collect([...$result['stored'], ...$result['replaced']])
            ->map(fn (StoneImage $image): array => $this->imageService->payload($image))
            ->values();

        if ($payloads->isEmpty() && $result['skipped'] === []) {
            throw ValidationException::withMessages($result['errors'] ?: [
                'files' => __('No images were saved.'),
            ]);
        }

        $parts = array_filter([
            $result['stored'] !== [] ? __(':count uploaded', ['count' => count($result['stored'])]) : null,
            $result['replaced'] !== [] ? __(':count replaced', ['count' => count($result['replaced'])]) : null,
            $result['skipped'] !== [] ? __(':count skipped (name already in the folder)', ['count' => count($result['skipped'])]) : null,
            $result['errors'] !== [] ? __(':count could not be saved', ['count' => count($result['errors'])]) : null,
        ]);

        return $this->jsonSuccess(ucfirst(implode(', ', $parts)).'.', [
            'images' => $payloads,
            'replaced' => collect($result['replaced'])->map(fn (StoneImage $image) => $image->uuid)->values(),
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ], 201);
    }

    /**
     * Rename the display filename.
     */
    public function update(RenameStoneImageRequest $request, StoneImage $stoneImage): JsonResponse
    {
        $this->authorize('update', $stoneImage);

        $image = $this->imageService->rename(
            $stoneImage,
            (string) $request->validated('name'),
            $request->user()
        );

        return $this->jsonSuccess(__('Name updated.'), [
            'image' => $this->imageService->payload($image),
        ]);
    }

    /**
     * Remove one picture from the folder.
     */
    public function destroy(StoneImage $stoneImage): JsonResponse
    {
        $this->authorize('delete', $stoneImage);

        $this->imageService->delete($stoneImage, request()->user());

        return $this->jsonSuccess(__('Image deleted.'));
    }

    /**
     * Delete every ticked picture in one go.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StoneImage::class);

        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:500'],
            'uuids.*' => ['string', 'max:64'],
        ]);

        $deleted = [];
        foreach (StoneImage::query()->whereIn('uuid', $data['uuids'])->get() as $image) {
            $this->authorize('delete', $image);
            $this->imageService->delete($image, $request->user());
            $deleted[] = $image->uuid;
        }

        return $this->jsonSuccess(__(':count images deleted.', ['count' => count($deleted)]), [
            'deleted' => $deleted,
        ]);
    }
}
