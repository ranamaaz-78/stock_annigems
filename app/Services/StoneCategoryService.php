<?php

namespace App\Services;

use App\Models\Stone;
use App\Models\StoneCategory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * StoneCategoryService
 *
 * Keeps the material folders in step with the lots filed inside them.
 *
 * Categories are never entered by hand: registering or importing a lot named
 * CITIRINO attaches it to the CITIRINO folder, creating that folder the first
 * time the name is seen. This is the only place folders are created, so the
 * manual form and the Excel import cannot drift apart.
 */
class StoneCategoryService
{
    /**
     * Folder for a material name, created if this is the first lot of it.
     *
     * A blank name has no folder — the caller's validation rejects those
     * rows long before this point, but an import row edited to empty must
     * not create a nameless folder.
     *
     * @param  string  $name Material name exactly as the operator typed it
     */
    public function resolve(string $name, User $actor): ?StoneCategory
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $branchId = (int) $actor->branch_id;
        $matchKey = StoneCategory::matchKeyFor($name);

        $existing = $this->find($branchId, $matchKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return StoneCategory::query()->create([
                'branch_id' => $branchId,
                // The first spelling seen becomes the folder label; later
                // lots match on the normalised key regardless of casing.
                'name' => $name,
                'match_key' => $matchKey,
                'is_active' => true,
            ]);
        } catch (QueryException $e) {
            // Two rows of the same new material inside one import can race
            // to create the folder; the loser simply reuses the winner's.
            $raced = $this->find($branchId, $matchKey);

            if ($raced === null) {
                throw $e;
            }

            return $raced;
        }
    }

    /**
     * Folders for the listing, with the figures shown on each folder tile.
     *
     * Counts are aggregated in the query rather than by loading the lots:
     * a branch can hold thousands of them.
     *
     * @param  array<string, mixed>  $filters Only `q` is honoured here
     * @return LengthAwarePaginator<int, StoneCategory>
     */
    public function paginate(array $filters, int $perPage = 24): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return StoneCategory::query()
            ->withCount([
                'stones as lot_count',
                'stones as active_lot_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->withSum('stones as piece_total', 'quantity')
            // Gram lots hold weight, not pieces; carat is weight per piece x pieces.
            ->withSum('stones as gram_total', 'weight_grams')
            ->withSum('stones as carat_total', DB::raw('carat * quantity'))
            ->when($term !== '', fn ($query) => $query->where('name', 'like', '%'.$term.'%'))
            ->ordered()
            ->paginate(per_page($perPage))
            ->withQueryString();
    }

    /**
     * Re-file a lot after its name was edited.
     *
     * Returns the folder the lot now belongs in. The old folder is left in
     * place even if it empties: an empty folder is a harmless record of a
     * material the branch has carried, and deleting it would break the
     * audit trail of the lots that moved out.
     */
    public function syncFor(Stone $stone, User $actor): ?StoneCategory
    {
        $category = $this->resolve((string) $stone->name, $actor);

        if ($category !== null && (int) $stone->stone_category_id !== (int) $category->id) {
            $stone->stone_category_id = $category->id;
            $stone->saveQuietly();
        }

        return $category;
    }

    /**
     * Folder names for the list filter dropdown.
     *
     * @return Collection<int, StoneCategory>
     */
    public function options(): Collection
    {
        return StoneCategory::query()->ordered()->get(['uuid', 'name']);
    }

    /**
     * Look up one folder by its normalised name within a branch.
     */
    private function find(int $branchId, string $matchKey): ?StoneCategory
    {
        return StoneCategory::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('match_key', $matchKey)
            ->first();
    }
}
