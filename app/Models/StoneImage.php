<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;

/**
 * StoneImage
 *
 * One picture in a branch's Stones image folder. The file lives on the
 * private stone-images disk at `path`. `name` is the unique label without
 * an extension (stone1, not stone1.jpg). `name_key` is that label in
 * lowercase and is the column search uses.
 */
class StoneImage extends Model
{
    use BelongsToBranch;
    use HasUuid;
    use TracksUserAttribution;

    /**
     * Quantity-style mass assignment. Uuid and attribution are trait-owned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'name',
        'name_key',
        'path',
        'mime',
        'size_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * Address of the picture. The version changes when the file is replaced,
     * so a browser never keeps showing the old picture under the same name.
     */
    public function url(): string
    {
        return route('branch.stone-images.show', [
            'stoneImage' => $this,
            'v' => $this->updated_at?->timestamp,
        ]);
    }

    /**
     * Lowercased key used by the unique index.
     */
    public static function nameKey(string $name): string
    {
        return mb_strtolower($name);
    }

    /**
     * Turn text typed (or read from a sheet) into a lookup code.
     *
     * Drops a trailing image extension, because operators type either
     * "stone1" or "stone1.jpg" and both mean the picture labelled stone1.
     * Returns null for a blank cell so the lot simply has no picture.
     */
    public static function codeFor(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));
        $allowed = array_merge(...array_values(\App\Services\StoneImageService::ALLOWED));

        if (in_array($extension, $allowed, true)) {
            $value = trim((string) pathinfo($value, PATHINFO_FILENAME));
        }

        return $value === '' ? null : mb_strtolower($value);
    }
}
