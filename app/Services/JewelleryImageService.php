<?php

namespace App\Services;

use App\Models\JewelleryImage;
use App\Models\JewelleryItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * JewelleryImageService
 *
 * Stores piece photos on the private jewellery disk (not public/). MIME is
 * checked from file contents, not the client extension.
 */
class JewelleryImageService
{
    /**
     * Allowed real MIME types.
     *
     * @var list<string>
     */
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Persist uploaded files and apply primary / sort_order.
     *
     * @param  list<UploadedFile>  $files
     * @param  list<array{id?: int, is_primary?: bool, sort_order?: int, keep?: bool}>  $existingMeta
     */
    public function sync(JewelleryItem $item, array $files, array $existingMeta, ?int $primaryExistingId): void
    {
        $maxCount = (int) config('annigems.jewellery.image_max_count', 8);
        $kept = $item->images()->count();
        $incoming = count($files);

        if ($kept + $incoming > $maxCount) {
            throw ValidationException::withMessages([
                'images' => __('At most :maxCount images are allowed.', ['maxCount' => $maxCount]),
            ]);
        }

        foreach ($existingMeta as $meta) {
            $image = $item->images()->where('id', (int) ($meta['id'] ?? 0))->first();
            if (! $image) {
                continue;
            }
            if (array_key_exists('sort_order', $meta)) {
                $image->sort_order = (int) $meta['sort_order'];
            }
            $image->is_primary = false;
            $image->save();
        }

        $stored = [];
        foreach ($files as $index => $file) {
            $this->assertSafeImage($file);
            $name = Str::uuid()->toString().'.'.$this->extensionForMime($file);
            $path = $item->uuid.'/'.$name;
            Storage::disk('jewellery')->put($path, file_get_contents($file->getRealPath()));
            $stored[] = JewelleryImage::query()->create([
                'jewellery_item_id' => $item->id,
                'path' => $path,
                'is_primary' => false,
                'sort_order' => $kept + $index,
            ]);
        }

        $item->unsetRelation('images');
        $images = $item->images()->get();

        if ($images->isEmpty()) {
            return;
        }

        $primary = null;
        if ($primaryExistingId) {
            $primary = $images->firstWhere('id', $primaryExistingId);
        }
        if (! $primary && $stored !== []) {
            $primary = $stored[0];
        }
        if (! $primary) {
            $primary = $images->first();
        }

        foreach ($images as $image) {
            $image->is_primary = $primary !== null && $image->id === $primary->id;
            $image->save();
        }
    }

    /**
     * Delete one image file and row; promote another to primary if needed.
     */
    public function delete(JewelleryItem $item, JewelleryImage $image): void
    {
        Storage::disk('jewellery')->delete($image->path);
        $wasPrimary = $image->is_primary;
        $image->delete();

        if ($wasPrimary) {
            $next = $item->images()->first();
            if ($next) {
                $next->is_primary = true;
                $next->save();
            }
        }
    }

    /**
     * Absolute path for a streaming response, or null if missing.
     */
    public function absolutePath(JewelleryImage $image): ?string
    {
        $disk = Storage::disk('jewellery');
        if (! $disk->exists($image->path)) {
            return null;
        }

        return $disk->path($image->path);
    }

    /**
     * Reject non-images even if the extension claims otherwise.
     */
    private function assertSafeImage(UploadedFile $file): void
    {
        $maxKb = (int) config('annigems.jewellery.image_max_kilobytes', 2048);
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'images' => __('Each image must be at most :maxKb KB.', ['maxKb' => $maxKb]),
            ]);
        }

        $mime = $this->realMime($file);
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'images' => __('Images must be JPEG, PNG, or WebP.'),
            ]);
        }
    }

    /**
     * MIME from file contents.
     */
    private function realMime(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file($file->getRealPath());
    }

    /**
     * @param  UploadedFile  $file
     */
    private function extensionForMime(UploadedFile $file): string
    {
        return match ($this->realMime($file)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
