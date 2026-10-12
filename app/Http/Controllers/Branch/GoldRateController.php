<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\GoldRate;
use App\Models\Purity;
use App\Services\GoldRateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GoldRateController
 *
 * Read-only gold rate view for branch Admin and staff. Rates are set by
 * SuperAdmin; this screen shows current values and history only.
 */
class GoldRateController extends Controller
{
    public function __construct(
        private GoldRateService $goldRateService,
    ) {}

    /**
     * Read-only rates and history with informational banner.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', GoldRate::class);

        return view('branch.gold-rates.index', [
            'currentRates' => $this->goldRateService->currentRates(),
            'history' => $this->goldRateService->history($request->only(['from', 'to', 'purity_id'])),
            'purities' => Purity::query()->active()->ordered()->get(),
            'filters' => $request->only(['from', 'to', 'purity_id']),
            'readonly' => true,
        ]);
    }
}
