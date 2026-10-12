<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BrandImageService
 *
 * Stores the company logo and favicon on the public disk so printed
 * documents and unauthenticated verify pages can load them. MIME is
 * read from file contents, never from the client extension.
 */
class BrandImageService
{
    /**
     * @var list<string>
     */
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'];

    /**
     * Store an uploaded brand image and return the public-relative path.
     *
     * @param  array{max_kb: int, max_width: int, max_height: int}  $limits
     */
    public function store(UploadedFile $file, string $kind, array $limits): string
    {
        $this->assertSafeImage($file, $kind, $limits);

        $name = $kind.'-'.Str::uuid()->toString().'.'.$this->extensionForMime($file);
        $path = 'brand/'.$name;

        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * Public URL for a stored brand path, or null.
     */
    public function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Reject forged extensions, oversized files and oversized dimensions.
     *
     * @param  array{max_kb: int, max_width: int, max_height: int}  $limits
     */
    private function assertSafeImage(UploadedFile $file, string $kind, array $limits): void
    {
        if ($file->getSize() > $limits['max_kb'] * 1024) {
            throw ValidationException::withMessages([
                $kind => 'That image must be at most '.$limits['max_kb'].' KB.',
            ]);
        }

        $mime = $this->realMime($file);

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                $kind => 'Upload a JPEG, PNG, WebP or ICO file.',
            ]);
        }

        if (in_array($mime, ['image/x-icon', 'image/vnd.microsoft.icon'], true)) {
            return;
        }

        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            throw ValidationException::withMessages([
                $kind => 'That file is not a readable image.',
            ]);
        }

        [$width, $height] = $size;

        if ($width > $limits['max_width'] || $height > $limits['max_height']) {
            throw ValidationException::withMessages([
                $kind => 'That image is larger than '.$limits['max_width'].'×'.$limits['max_height'].' pixels.',
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
     * Extension matching the real MIME, never the client name.
     */
    private function extensionForMime(UploadedFile $file): string
    {
        return match ($this->realMime($file)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => 'jpg',
        };
    }
}
