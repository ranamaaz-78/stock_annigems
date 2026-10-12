<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\DashboardStatsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * DashboardController
 *
 * SuperAdmin home. Carries the same All Branches / Single Branch selector the
 * SuperAdmin reports use (spec 8.1), and shows aggregate figures only — no
 * invoice, customer, item or stone level data appears anywhere on it.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected DashboardStatsService $stats
    ) {}

    /**
     * Display the SuperAdmin dashboard for the selected scope.
     *
     * The branch is read from the query string by uuid, which is safe here and
     * only here: this is the one actor allowed to choose a branch.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewDashboard');
        $this->authorize('viewAny', Branch::class);

        $branchUuid = (string) $request->query('branch', '');
        $branch = $branchUuid !== ''
            ? Branch::query()->where('uuid', $branchUuid)->first()
            : null;

        return view('superadmin.dashboard.index', [
            'dashboard' => $this->stats->forSuperAdmin($request->user(), $branch?->id),
            'branches' => Branch::query()->orderBy('name')->get(['uuid', 'name']),
            'selectedBranch' => $branch?->uuid,
        ]);
    }
}
