<?php

namespace App\Services;

use App\Models\Stone;
use App\Models\StoneCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * StoneFolderService
 *
 * Branch-admin edits to a material folder: rename it, or delete it together
 * with every lot inside. Folders are otherwise created automatically from lot
 * names (StoneCategoryService), so these are the only two ways to change one
 * by hand.
 */
class StoneFolderService
{
    /**
     * How many blocking lots the refusal message lists before summarising.
     */
    private const BLOCKER_LIST_LIMIT = 5;

    public function __construct(
        private AuditLogService $auditLogService,
        private StoneService $stoneService,
    ) {}

    /**
     * Change the label shown on a folder.
     *
     * Only the label moves. The folder keeps its match_key, so lots that
     * arrive under the old spelling (an Excel row, a typed name) still file
     * into it, and no lot's own name is touched.
     *
     * @throws ValidationException When the name is another folder's name
     */
    public function rename(StoneCategory $category, string $name, User $actor): StoneCategory
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter a folder name.']);
        }

        $key = StoneCategory::matchKeyFor($name);

        // Renaming onto another folder would merge two folders, which this
        // action does not do: it would silently re-file every lot of one.
        $clash = StoneCategory::query()
            ->where('branch_id', $category->branch_id)
            ->whereKeyNot($category->id)
            ->where(fn ($query) => $query
                ->where('match_key', $key)
                ->orWhereRaw('UPPER(name) = ?', [$key]))
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'name' => "A folder named {$clash->name} already exists.",
            ]);
        }

        if ($category->name === $name) {
            return $category;
        }

        $old = ['name' => $category->name];

        $category->name = $name;
        $category->save();

        $this->auditLogService->log('stone_category.renamed', $category, $old, ['name' => $category->name], $actor);

        return $category;
    }

    /**
     * Delete a folder and every lot in it, or nothing at all.
     *
     * Each lot goes through StoneService::delete, so remaining stock is
     * written off in the ledger and invoices keep their snapshots, exactly as
     * if the lots were deleted one by one. If any lot is still held by an open
     * document the whole delete is refused before anything changes.
     *
     * @return array{lots: int}
     *
     * @throws ValidationException When any lot is held by an open document
     */
    public function delete(StoneCategory $category, User $actor): array
    {
        $this->assertNoLotIsHeld($category);

        return DB::transaction(function () use ($category, $actor): array {
            $lots = 0;

            // Lock the folder first so a lot registered into it mid-delete
            // waits and is then deleted with the rest, not left behind.
            $category = StoneCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            // By id, not by offset: each delete removes the row from this very
            // query, and offset paging would skip every other lot.
            $category->stones()->eachById(function (Stone $stone) use ($actor, &$lots): void {
                $this->stoneService->delete($stone, $actor);
                $lots++;
            });

            $old = ['name' => $category->name, 'lots' => $lots];

            // The row stays (soft delete, for the audit trail), so free its
            // key: otherwise a later lot with this name would resolve to a
            // folder nobody can see.
            $category->match_key = Str::limit($category->match_key, 100, '').'~deleted-'.$category->id;
            $category->save();
            $category->delete();

            $this->auditLogService->log('stone_category.deleted', $category, $old, null, $actor);

            return ['lots' => $lots];
        });
    }

    /**
     * Refuse when any lot of the folder is held by an open document.
     *
     * Checks every lot and reports the first few, so the operator can clear
     * them all in one pass instead of retrying once per lot.
     */
    private function assertNoLotIsHeld(StoneCategory $category): void
    {
        $reasons = [];
        $total = 0;

        $category->stones()->eachById(function (Stone $stone) use (&$reasons, &$total): void {
            $reason = $this->stoneService->openDocumentReason($stone);

            if ($reason === null) {
                return;
            }

            $total++;

            if (count($reasons) < self::BLOCKER_LIST_LIMIT) {
                $reasons[] = $reason;
            }
        });

        if ($total === 0) {
            return;
        }

        $more = $total - count($reasons);

        throw ValidationException::withMessages([
            'folder' => 'Folder not deleted: '.$total.' '.Str::plural('lot', $total).' still held by open documents. '
                .implode(' ', $reasons)
                .($more > 0 ? " …and {$more} more." : ''),
        ]);
    }
}
