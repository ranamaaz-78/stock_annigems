<?php

namespace App\Http\Controllers\Branch;

use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\LookupStoneScanRequest;
use App\Http\Requests\Branch\StoreStoneIntakeDraftRequest;
use App\Http\Requests\Branch\StoreStoneIntakeRequest;
use App\Models\Stone;
use App\Models\StoneIntake;
use App\Models\User;
use App\Services\StoneIntakeDraftService;
use App\Services\StoneIntakeService;
use App\Services\StoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * StoneIntakeController
 *
 * Scan page, draft autosave, lookup, save, history, and immutable show.
 */
class StoneIntakeController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private StoneIntakeService $intakeService,
        private StoneIntakeDraftService $draftService,
        private StoneService $stoneService,
    ) {}

    /**
     * Intake history list.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StoneIntake::class);

        $users = User::query()->orderBy('name')->get(['id', 'uuid', 'name', 'role']);

        return view('branch.stone-intakes.index', [
            'intakes' => $this->intakeService->paginate($request->only(['from', 'to', 'performed_by'])),
            'filters' => $request->only(['from', 'to', 'performed_by']),
            'users' => $users,
        ]);
    }

    /**
     * Scan / intake workspace.
     */
    public function scan(): View
    {
        $this->authorize('create', StoneIntake::class);

        $draft = $this->draftService->current($requestUser = request()->user());

        return view('branch.stone-intakes.scan', [
            'draft' => $draft,
            'scanner' => scanner_config(),
            'draftDebounceMs' => (int) config('annigems.stones.draft_debounce_ms', 800),
            // The quick-register modal embeds branch.stones._form, which needs
            // the same option lists the full registration page uses.
            'pricingModes' => StonePricingMode::options(),
            'statuses' => StoneStatus::options(),
            'canRegister' => $requestUser->hasPermission('stones.create'),
        ]);
    }

    /**
     * Lookup a barcode or stone code in this branch.
     */
    public function lookup(LookupStoneScanRequest $request): JsonResponse
    {
        $result = $this->stoneService->lookupScan(
            $request->validated('code'),
            (int) $request->user()->branch_id
        );

        return match ($result['status']) {
            'found' => $this->jsonSuccess('Found.', [
                'stone' => $this->stoneService->toScanPayload($result['stone']),
            ]),
            'inactive' => $this->jsonFailure('This stone is deactivated. Reactivate it before adding stock.', [
                'code' => ['This stone is deactivated.'],
            ], 422),
            'other_branch' => $this->jsonFailure('Not found in this branch.', [
                'code' => ['Not found in this branch.'],
            ], 404),
            default => $this->jsonFailure('Barcode not found.', [
                'code' => ['Barcode not found.'],
            ], 404),
        };
    }

    /**
     * Debounced draft upsert.
     */
    public function saveDraft(StoreStoneIntakeDraftRequest $request): JsonResponse
    {
        $this->draftService->save($request->user(), $request->validated());

        return $this->jsonSuccess('Draft saved.');
    }

    /**
     * Discard recovered / in-progress draft.
     */
    public function discardDraft(): JsonResponse
    {
        $this->authorize('create', StoneIntake::class);
        $this->draftService->discard(request()->user());

        return $this->jsonSuccess('Draft discarded.');
    }

    /**
     * Commit the batch.
     */
    public function store(StoreStoneIntakeRequest $request): JsonResponse|RedirectResponse
    {
        try {
            $intake = $this->intakeService->save($request->validated(), $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure(collect($e->errors())->flatten()->first() ?? 'Could not save intake.', $e->errors());
            }
            throw $e;
        }

        $payload = [
            'intake_number' => $intake->intake_number,
            'uuid' => $intake->uuid,
            'show_url' => route('branch.stone-intakes.show', $intake),
            'scan_url' => route('branch.stone-intakes.scan'),
        ];

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Intake '.$intake->intake_number.' saved.', $payload, 201);
        }

        return redirect()
            ->route('branch.stone-intakes.show', $intake)
            ->with('success', 'Intake '.$intake->intake_number.' saved.');
    }

    /**
     * Immutable intake detail.
     */
    public function show(StoneIntake $stoneIntake): View
    {
        $this->authorize('view', $stoneIntake);

        $stoneIntake->load(['items.stone', 'creator', 'branch']);

        return view('branch.stone-intakes.show', [
            'intake' => $stoneIntake,
        ]);
    }
}
