<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Services\TrashService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * TrashController
 *
 * Soft delete of the six record types and the Trash page that lists and
 * restores them. There is deliberately no permanent-delete action.
 */
class TrashController extends Controller
{
    public function __construct(private TrashService $trash) {}

    /**
     * Trash page: one tab per record type the user may delete.
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()->hasPermission('trash.view'), 403);

        $types = $this->trash->typesFor($request->user());
        abort_if($types === [], 403);

        $tab = $request->query('tab');
        $tab = in_array($tab, $types, true) ? $tab : $types[0];

        return view('branch.trash.index', [
            'types' => $types,
            'tab' => $tab,
            'labels' => collect(TrashService::TYPES)->map(fn (array $t): string => $t['label']),
            'counts' => $this->trash->counts($types),
            'records' => $this->trash->trashed($tab),
            'trash' => $this->trash,
        ]);
    }

    /**
     * Move a record to the Trash.
     */
    public function destroy(Request $request, string $type, string $uuid): RedirectResponse
    {
        $config = $this->config($request, $type);
        $record = $config['model']::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $this->trash->delete($record, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()
            ->route('branch.'.$type.'.index')
            ->with('success', __('Moved to Trash. You can restore it from the Trash page.'));
    }

    /**
     * Bring a record back from the Trash.
     */
    public function restore(Request $request, string $type, string $uuid): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('trash.view'), 403);

        $config = $this->config($request, $type);
        $record = $config['model']::onlyTrashed()->where('uuid', $uuid)->firstOrFail();

        try {
            $this->trash->restore($record, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', __('Restored.'));
    }

    /**
     * Resolve a type slug and require its delete permission.
     *
     * @return array{model: class-string<\Illuminate\Database\Eloquent\Model>, permission: string, label: string}
     */
    private function config(Request $request, string $type): array
    {
        $config = TrashService::TYPES[$type] ?? null;
        abort_if($config === null, 404);
        abort_unless($request->user()->hasPermission($config['permission']), 403);

        return $config;
    }
}
