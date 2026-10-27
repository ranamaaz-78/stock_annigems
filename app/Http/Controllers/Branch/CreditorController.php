<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreCreditorRequest;
use App\Http\Requests\Branch\UpdateCreditorRequest;
use App\Models\Creditor;
use App\Services\CreditorService;
use App\Services\PartyExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

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
            'creditors' => $this->creditorService->paginate($request->only(['search', 'status', 'city'])),
            'filters' => $request->only(['search', 'status', 'city']),
            'cities' => $this->creditorService->cities(),
        ]);
    }

    /**
     * Excel download of the rows the list filters match.
     */
    public function export(Request $request, PartyExportService $exportService): Response
    {
        $this->authorize('viewAny', Creditor::class);

        $rows = $this->creditorService->exportQuery($request->only(['search', 'status', 'city']));
        $city = trim((string) $request->query('city', ''));
        $title = 'Creditors'.($city !== '' ? " - {$city}" : '');
        $filename = sprintf('%s - %s.xlsx', $title, now()->format('Y-m-d'));
        $filename = trim((string) preg_replace('/\s+/u', ' ', str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], ' ', $filename)));

        return response($exportService->build($rows, 'creditor_code'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, Str::ascii($filename)),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * AJAX search for the performa creditor picker.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Creditor::class);

        $term = (string) $request->query('q', '');

        return $this->jsonSuccess(__('OK'), [
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
            return $this->jsonSuccess(__('Creditor created.'), [
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
            ->with('success', __('Creditor created successfully.'));
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
            ->with('success', __('Creditor updated successfully.'));
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
            'confirm.accepted' => __('Please confirm deactivation.'),
        ]);

        $this->creditorService->deactivate($creditor, $request->user());

        return redirect()
            ->route('branch.creditors.show', $creditor)
            ->with('success', __('Creditor deactivated. They no longer appear when issuing a performa.'));
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
            ->with('success', __('Creditor activated.'));
    }
}
