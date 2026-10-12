<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\BranchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreBranchRequest;
use App\Http\Requests\SuperAdmin\UpdateBranchRequest;
use App\Models\Branch;
use App\Services\BranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BranchController
 *
 * Thin HTTP layer for SuperAdmin branch CRUD and status toggles.
 */
class BranchController extends Controller
{
    public function __construct(
        protected BranchService $branchService
    ) {}

    /**
     * List branches with search and filters.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Branch::class);

        $filters = $request->only(['search', 'status', 'city']);

        return view('superadmin.branches.index', [
            'branches' => $this->branchService->list($filters),
            'cities' => $this->branchService->cities(),
            'filters' => $filters,
        ]);
    }

    /**
     * Show branch detail with users.
     */
    public function show(Branch $branch): View
    {
        $this->authorize('view', $branch);

        return view('superadmin.branches.show', [
            'branch' => $this->branchService->findWithUsers($branch),
            'auditLogs' => $this->branchService->auditEntries($branch),
        ]);
    }

    /**
     * Show create form (full page; slide-over also posts here).
     */
    public function create(): View
    {
        $this->authorize('create', Branch::class);

        return view('superadmin.branches.create');
    }

    /**
     * Persist a new branch.
     */
    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $this->authorize('create', Branch::class);

        $branch = $this->branchService->create($request->validated(), $request->user());

        return redirect()
            ->route('superadmin.branches.show', $branch)
            ->with('success', 'Branch created successfully.');
    }

    /**
     * Show edit form.
     */
    public function edit(Branch $branch): View
    {
        $this->authorize('update', $branch);

        return view('superadmin.branches.edit', compact('branch'));
    }

    /**
     * Update an existing branch.
     */
    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->authorize('update', $branch);

        $this->branchService->update($branch, $request->validated(), $request->user());

        return redirect()
            ->route('superadmin.branches.show', $branch)
            ->with('success', 'Branch updated successfully.');
    }

    /**
     * Deactivate a branch (blocks its users from logging in).
     */
    public function deactivate(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorize('toggleStatus', $branch);

        $this->branchService->setStatus($branch, BranchStatus::INACTIVE, $request->user());

        return back()->with('success', 'Branch deactivated. Its users can no longer log in.');
    }

    /**
     * Activate a previously inactive branch.
     */
    public function activate(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorize('toggleStatus', $branch);

        $this->branchService->setStatus($branch, BranchStatus::ACTIVE, $request->user());

        return back()->with('success', 'Branch activated successfully.');
    }
}
