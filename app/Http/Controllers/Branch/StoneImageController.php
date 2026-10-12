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

        return $this->jsonSuccess('Checked.', [
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
                'files' => 'No images were saved.',
            ]);
        }

        $parts = array_filter([
            $result['stored'] !== [] ? count($result['stored']).' uploaded' : null,
            $result['replaced'] !== [] ? count($result['replaced']).' replaced' : null,
            $result['skipped'] !== [] ? count($result['skipped']).' skipped (name already in the folder)' : null,
            $result['errors'] !== [] ? count($result['errors']).' could not be saved' : null,
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

        return $this->jsonSuccess('Name updated.', [
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

        return $this->jsonSuccess('Image deleted.');
    }
}
