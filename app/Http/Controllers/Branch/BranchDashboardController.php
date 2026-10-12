<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Services\DashboardStatsService;
use Illuminate\View\View;

/**
 * BranchDashboardController
 *
 * Branch operations home. Card content varies by role; the decision of which
 * cards exist is made inside DashboardStatsService so nothing is computed for a
 * user who may not see it.
 */
class BranchDashboardController extends Controller
{
    public function __construct(
        protected DashboardStatsService $stats
    ) {}

    /**
     * Role-appropriate dashboard for the authenticated branch user.
     */
    public function index(): View
    {
        $user = auth()->user();

        $this->authorize('viewBranch', $user);
        $this->authorize('viewDashboard');

        return view('branch.dashboard.index', [
            'dashboard' => $this->stats->forBranchUser($user),
        ]);
    }
}
