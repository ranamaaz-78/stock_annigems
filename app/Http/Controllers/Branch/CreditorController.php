<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreCreditorRequest;
use App\Http\Requests\Branch\UpdateCreditorRequest;
use App\Models\Creditor;
use App\Services\CreditorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CreditorController
 *
 * Branch-scoped creditor CRUD and AJAX search for the performa picker.
 */
class CreditorController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private CreditorService $creditorService,
    ) {}

    /**
     * Creditor registry list.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Creditor::class);

        return view('branch.creditors.index', [
            'creditors' => $this->creditorService->paginate($request->only(['search', 'status'])),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * AJAX search for the performa creditor picker.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Creditor::class);

        $term = (string) $request->query('q', '');

        return $this->jsonSuccess('OK', [
            // values() so JSON is a list. Eloquent map() keeps row ids as
            // keys; that encodes as an object and Select2's .map() throws.
            'creditors' => ($request->boolean('catalog') ? $this->creditorService->catalog() : $this->creditorService->search($term))->map(fn (Creditor $c): array => [
                'uuid' => $c->uuid,
                'creditor_code' => $c->creditor_code,
                'name' => $c->name,
                'phone' => $c->phone,
                'email' => $c->email,
                'nie' => $c->nie,
                'address' => $c->address,
                'city' => $c->city,
            ])->values(),
        ]);
    }

    /**
     * Create form.
     */
    public function create(): View
    {
        $this->authorize('create', Creditor::class);

        return view('branch.creditors.create');
    }

    /**
     * Persist a new creditor.
     */
    public function store(StoreCreditorRequest $request): RedirectResponse|JsonResponse
    {
        $creditor = $this->creditorService->create($request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Creditor created.', [
                'creditor' => [
                    'uuid' => $creditor->uuid,
                    'creditor_code' => $creditor->creditor_code,
                    'name' => $creditor->name,
                    'phone' => $creditor->phone,
                ],
            ], 201);
        }

        return redirect()
            ->route('branch.creditors.show', $creditor)
            ->with('success', 'Creditor created successfully.');
    }

    /**
     * Creditor detail with performa history.
     */
    public function show(Creditor $creditor): View
    {
        $this->authorize('view', $creditor);

        return view('branch.creditors.show', [
            'creditor' => $creditor->load(['creator', 'updater']),
            'performas' => $this->creditorService->performaHistory($creditor),
        ]);
    }

    /**
     * Edit form.
     */
    public function edit(Creditor $creditor): View
    {
        $this->authorize('update', $creditor);

        return view('branch.creditors.edit', ['creditor' => $creditor]);
    }

    /**
     * Save creditor edits.
     */
    public function update(UpdateCreditorRequest $request, Creditor $creditor): RedirectResponse
    {
        $this->creditorService->update($creditor, $request->validated(), $request->user());

        return redirect()
            ->route('branch.creditors.show', $creditor)
            ->with('success', 'Creditor updated successfully.');
    }

    /**
     * Deactivate creditor (status flag — hidden from the performa picker).
     */
    public function deactivate(Request $request, Creditor $creditor): RedirectResponse
    {
        $this->authorize('deactivate', $creditor);

        $request->validate([
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Please confirm deactivation.',
        ]);

        $this->creditorService->deactivate($creditor, $request->user());

        return redirect()
            ->route('branch.creditors.show', $creditor)
            ->with('success', 'Creditor deactivated. They no longer appear when issuing a performa.');
    }

    /**
     * Reactivate so the creditor can be picked on a new performa.
     */
    public function activate(Request $request, Creditor $creditor): RedirectResponse
    {
        $this->authorize('deactivate', $creditor);

        $this->creditorService->activate($creditor, $request->user());

        return redirect()
            ->route('branch.creditors.show', $creditor)
            ->with('success', 'Creditor activated.');
    }
}
