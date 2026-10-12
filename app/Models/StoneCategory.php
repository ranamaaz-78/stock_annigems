<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StoneCategory
 *
 * A folder of stone lots that share a material name — every CITIRINO lot,
 * every AMATISTA lot, and so on. Categories are created from the lot name
 * rather than chosen by the operator, so the folders always mirror what is
 * actually in stock.
 *
 * Branch-owned: two branches each keep their own CITIRINO folder.
 */
class StoneCategory extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'name',
        'match_key',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Normalise a material name into the key used to match lots to folders.
     *
     * Case and stray spacing differ constantly across supplier rows, so
     * "citirino", "CITIRINO" and "Citirino  " all have to land in one
     * folder. Genuinely different spellings stay separate on purpose —
     * only a human can tell whether they are the same stone.
     */
    public static function matchKeyFor(string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * What is in stock in this folder, in the units each lot is counted in.
     *
     * Piece and carat lots count pieces, gram lots count grams, and carat
     * lots also carry a total carat weight. Only the figures that exist are
     * shown, so a folder of gram lots reads "150 g" rather than "0 pcs". A
     * folder with nothing in stock still says "0 pcs".
     *
     * Needs the piece_total, gram_total and carat_total sums that
     * StoneCategoryService::paginate() loads.
     */
    public function stockSummary(): string
    {
        $trim = fn (float $value, int $decimals): string => rtrim(rtrim(number_format($value, $decimals), '0'), '.');

        $parts = [];

        if ((int) $this->piece_total > 0) {
            $parts[] = number_format((int) $this->piece_total).' pcs';
        }
        if ((float) $this->carat_total > 0) {
            $parts[] = $trim((float) $this->carat_total, 3).' ct';
        }
        if ((float) $this->gram_total > 0) {
            $parts[] = $trim((float) $this->gram_total, 3).' g';
        }

        return $parts === [] ? '0 pcs' : implode(' · ', $parts);
    }

    /**
     * Lots filed in this folder.
     */
    public function stones(): HasMany
    {
        return $this->hasMany(Stone::class);
    }

    /**
     * Folders with at least one lot still on the books, most stocked first.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }
}
