<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\RenameStoneFolderRequest;
use App\Models\StoneCategory;
use App\Services\StoneFolderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * StoneFolderController
 *
 * HTTP for renaming or deleting a material folder. The rules live in
 * StoneFolderService; both actions are branch-admin only.
 */
class StoneFolderController extends Controller
{
    public function __construct(private StoneFolderService $folderService) {}

    /**
     * Rename a folder. Its lots keep their own names.
     */
    public function update(RenameStoneFolderRequest $request, StoneCategory $stoneCategory): RedirectResponse
    {
        $this->authorize('manage', $stoneCategory);

        // From the folder list the operator stays on the list; from the
        // folder's own page they land back on that folder.
        $fromList = $this->fromFolderList($request);

        try {
            $category = $this->folderService->rename($stoneCategory, (string) $request->validated('name'), $request->user());
        } catch (ValidationException $e) {
            return ($fromList ? back() : redirect()->route('branch.stones.index', ['category' => $stoneCategory->uuid]))
                ->with('error', collect($e->errors())->flatten()->first());
        }

        return ($fromList ? back() : redirect()->route('branch.stones.index', ['category' => $category->uuid]))
            ->with('success', __('Folder renamed to :name.', ['name' => $category->name]));
    }

    /**
     * Delete a folder and every lot in it, or refuse and change nothing.
     */
    public function destroy(Request $request, StoneCategory $stoneCategory): RedirectResponse
    {
        $this->authorize('manage', $stoneCategory);

        $name = $stoneCategory->name;

        try {
            $result = $this->folderService->delete($stoneCategory, $request->user());
        } catch (ValidationException $e) {
            return ($this->fromFolderList($request) ? back() : redirect()->route('branch.stones.index', ['category' => $stoneCategory->uuid]))
                ->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()
            ->route('branch.stones.index')
            ->with('success', trans_choice('Folder :name deleted with :count lot.|Folder :name deleted with :count lots.', $result['lots'], ['name' => $name]));
    }

    /**
     * Delete the ticked lots of the list, or none of them.
     */
    public function destroyLots(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('stones.delete'), 403);

        $uuids = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:500'],
            'uuids.*' => ['string'],
        ], [
            'uuids.required' => __('Select at least one lot to delete.'),
        ])['uuids'];

        try {
            $result = $this->folderService->deleteLots($uuids, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', trans_choice(':count lot deleted.|:count lots deleted.', $result['lots']));
    }

    /**
     * Whether the request came from a tile on the folder list.
     */
    private function fromFolderList(Request $request): bool
    {
        return $request->input('return') === 'folders';
    }
}
