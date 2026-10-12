<?php

namespace App\Services;

use App\Models\StoneImage;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * StoneImageService
 *
 * The Stones image folder. Files sit on the private stone-images disk under
 * a generated path. The name the operator uploaded is stored on its own
 * column and must be unique in the branch, including case. Rename changes
 * only that label.
 */
class StoneImageService
{
    /**
     * Real MIME types the folder accepts, mapped to the extensions a
     * filename may end with.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
    ];

    /** What to do with an upload whose name is already in the folder. */
    public const ON_DUPLICATE_SKIP = 'skip';

    public const ON_DUPLICATE_REPLACE = 'replace';

    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * Pictures in the actor's branch, newest first.
     *
     * @return Collection<int, StoneImage>
     */
    public function list(): Collection
    {
        return StoneImage::query()->orderByDesc('id')->get();
    }

    /**
     * Which of these client file names are already in the actor's folder.
     *
     * Names are compared the way the folder compares them: without the
     * extension and ignoring case. Unusable names are left out here and
     * reported when the file itself is uploaded.
     *
     * @param  list<string>  $clientNames
     * @return list<string> The folder's own spelling of each name that exists
     */
    public function existingNames(array $clientNames, int $branchId): array
    {
        $keys = [];

        foreach ($clientNames as $clientName) {
            try {
                $keys[StoneImage::nameKey($this->labelName((string) $clientName))] = true;
            } catch (ValidationException) {
                continue;
            }
        }

        if ($keys === []) {
            return [];
        }

        return StoneImage::query()
            ->where('branch_id', $branchId)
            ->whereIn('name_key', array_keys($keys))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Store every accepted file.
     *
     * A name already in the folder is handled by $onDuplicate: skipped,
     * replaced (same picture entry, new file), or, when nothing was chosen,
     * reported on that file without rolling back the rest of the drop.
     *
     * @param  list<UploadedFile>  $files
     * @param  string|null  $onDuplicate  self::ON_DUPLICATE_SKIP | self::ON_DUPLICATE_REPLACE | null
     * @return array{stored: list<StoneImage>, replaced: list<StoneImage>, skipped: list<string>, errors: array<string, list<string>>}
     */
    public function storeMany(array $files, User $actor, ?string $onDuplicate = null): array
    {
        $stored = [];
        $replaced = [];
        $skipped = [];
        $errors = [];

        foreach ($files as $index => $file) {
            try {
                $mime = $this->assertSafeImage($file);
                $name = $this->displayName($file, $mime);
                $existing = $onDuplicate === null ? null : $this->findByName($name, (int) $actor->branch_id);

                if ($existing !== null && $onDuplicate === self::ON_DUPLICATE_SKIP) {
                    $skipped[] = $existing->name;

                    continue;
                }

                if ($existing !== null && $onDuplicate === self::ON_DUPLICATE_REPLACE) {
                    $replaced[] = $this->replaceFile($existing, $file, $mime, $actor);

                    continue;
                }

                $stored[] = $this->storeOne($file, $actor);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first()
                    ?: 'This image could not be saved.';
                $errors["files.{$index}"] = [$message];
            }
        }

        return ['stored' => $stored, 'replaced' => $replaced, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Persist one upload. The display name is the client's filename.
     */
    public function storeOne(UploadedFile $file, User $actor): StoneImage
    {
        $mime = $this->assertSafeImage($file);
        $name = $this->displayName($file, $mime);
        $this->assertNameAvailable($name, (int) $actor->branch_id);

        $extension = $this->extensionForMime($mime);
        $path = $actor->branch_id.'/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk('stone-images')->put($path, (string) file_get_contents($file->getRealPath()));

        try {
            $image = DB::transaction(function () use ($actor, $name, $path, $mime, $file): StoneImage {
                $image = StoneImage::query()->create([
                    'branch_id' => $actor->branch_id,
                    'name' => $name,
                    'name_key' => StoneImage::nameKey($name),
                    'path' => $path,
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                ]);

                $this->auditLogService->log('stone_image.created', $image, null, [
                    'name' => $image->name,
                    'path' => $image->path,
                ], $actor);

                return $image;
            });
        } catch (UniqueConstraintViolationException) {
            Storage::disk('stone-images')->delete($path);

            throw ValidationException::withMessages([
                'name' => "A file named {$name} is already in this folder.",
            ]);
        }

        return $image;
    }

    /**
     * Swap the file behind an existing picture, keeping its name and entry.
     *
     * The new file is written first and the old one removed only after the
     * row points at the new one, so a failure cannot leave a name with no
     * picture behind it.
     */
    private function replaceFile(StoneImage $image, UploadedFile $file, string $mime, User $actor): StoneImage
    {
        $oldPath = $image->path;
        $path = $image->branch_id.'/'.Str::uuid()->toString().'.'.$this->extensionForMime($mime);

        Storage::disk('stone-images')->put($path, (string) file_get_contents($file->getRealPath()));

        try {
            DB::transaction(function () use ($image, $path, $mime, $file, $oldPath, $actor): void {
                $image->fill([
                    'path' => $path,
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                ]);
                $image->save();

                $this->auditLogService->log('stone_image.replaced', $image, ['path' => $oldPath], [
                    'name' => $image->name,
                    'path' => $path,
                ], $actor);
            });
        } catch (\Throwable $exception) {
            Storage::disk('stone-images')->delete($path);

            throw $exception;
        }

        Storage::disk('stone-images')->delete($oldPath);

        return $image;
    }

    /**
     * The picture with this label in the branch, ignoring case.
     */
    private function findByName(string $name, int $branchId): ?StoneImage
    {
        return StoneImage::query()
            ->where('branch_id', $branchId)
            ->where('name_key', StoneImage::nameKey($name))
            ->first();
    }

    /**
     * Windows-style rename: the label changes, the file on disk stays put.
     */
    public function rename(StoneImage $image, string $name, User $actor): StoneImage
    {
        $name = $this->labelName($name);
        $this->assertNameAvailable($name, (int) $image->branch_id, (int) $image->id);

        $old = ['name' => $image->name];

        try {
            $image->name = $name;
            $image->name_key = StoneImage::nameKey($name);
            $image->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => "A file named {$name} is already in this folder.",
            ]);
        }

        $this->auditLogService->log('stone_image.renamed', $image, $old, [
            'name' => $image->name,
        ], $actor);

        return $image;
    }

    /**
     * Delete the row and the private file together.
     */
    public function delete(StoneImage $image, User $actor): void
    {
        $snapshot = ['name' => $image->name, 'path' => $image->path];
        Storage::disk('stone-images')->delete($image->path);
        $image->delete();
        $this->auditLogService->log('stone_image.deleted', $image, $snapshot, null, $actor);
    }

    /**
     * Absolute path for a streamed response, or null when the file is gone.
     */
    public function absolutePath(StoneImage $image): ?string
    {
        $disk = Storage::disk('stone-images');
        if (! $disk->exists($image->path)) {
            return null;
        }

        return $disk->path($image->path);
    }

    /**
     * Browser payload for one tile. Path stays on the server.
     *
     * @return array{uuid: string, name: string, url: string}
     */
    public function payload(StoneImage $image): array
    {
        return [
            'uuid' => $image->uuid,
            'name' => $image->name,
            'url' => $image->url(),
        ];
    }

    /**
     * Reject non-images and oversized files. MIME comes from contents.
     */
    private function assertSafeImage(UploadedFile $file): string
    {
        $maxKb = (int) config('annigems.stones.image_max_kilobytes', 5120);
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'files' => $file->getClientOriginalName().' is larger than '.$maxKb.' KB.',
            ]);
        }

        $mime = $this->realMime($file);
        if (! isset(self::ALLOWED[$mime])) {
            throw ValidationException::withMessages([
                'files' => $file->getClientOriginalName().' must be a JPEG, PNG, WebP, or GIF image.',
            ]);
        }

        return $mime;
    }

    /**
     * Display name is the client filename without its extension.
     * Search and uniqueness use this label (stone1), never stone1.jpg.
     */
    private function displayName(UploadedFile $file, string $mime): string
    {
        $original = $this->normalizeName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = self::ALLOWED[$mime];

        if (! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'files' => "{$original} does not match the image type inside the file.",
            ]);
        }

        return $this->labelName($original);
    }

    /**
     * Drop a trailing image extension so the stored name is what an operator types.
     */
    private function labelName(string $name): string
    {
        $name = $this->normalizeName($name);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = array_merge(...array_values(self::ALLOWED));

        if (in_array($extension, $allowed, true)) {
            $name = trim((string) pathinfo($name, PATHINFO_FILENAME));
        }

        if ($name === '' || $name === '.' || $name === '..') {
            throw ValidationException::withMessages([
                'name' => 'Enter a file name.',
            ]);
        }

        return $name;
    }

    /**
     * Strip a path and control characters. Empty names are rejected.
     */
    private function normalizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')) {
            throw ValidationException::withMessages([
                'name' => 'Enter a file name.',
            ]);
        }

        if (mb_strlen($name) > 180) {
            throw ValidationException::withMessages([
                'name' => 'File name must be 180 characters or fewer.',
            ]);
        }

        return $name;
    }

    /**
     * Case-insensitive uniqueness inside one branch. Lookup uses name_key,
     * which is indexed with branch_id.
     */
    private function assertNameAvailable(string $name, int $branchId, ?int $ignoreId = null): void
    {
        $taken = StoneImage::query()
            ->where('branch_id', $branchId)
            ->where('name_key', StoneImage::nameKey($name))
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "A file named {$name} is already in this folder.",
            ]);
        }
    }

    /**
     * MIME from file contents, not the client header.
     */
    private function realMime(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file((string) $file->getRealPath());
    }

    /**
     * Disk extension for a verified MIME type.
     */
    private function extensionForMime(string $mime): string
    {
        return self::ALLOWED[$mime][0];
    }
}
