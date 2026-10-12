<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreGoldRatesRequest;
use App\Models\GoldRate;
use App\Models\Purity;
use App\Services\GoldRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GoldRateController
 *
 * SuperAdmin gold rate management — insert-only history per purity.
 */
class GoldRateController extends Controller
{
    public function __construct(
        private GoldRateService $goldRateService,
    ) {}

    /**
     * Current rates, update form, and history table.
     */
    public function index(Request $request): View
    {
        $this->authorize('manage', GoldRate::class);

        return view('superadmin.gold-rates.index', [
            'currentRates' => $this->goldRateService->currentRates(),
            'history' => $this->goldRateService->history($request->only(['from', 'to', 'purity_id'])),
            'purities' => Purity::query()->active()->ordered()->get(),
            'filters' => $request->only(['from', 'to', 'purity_id']),
            'readonly' => false,
            'deviationThreshold' => (int) config('annigems.gold_rates.deviation_warn_percent', 20),
        ]);
    }

    /**
     * Insert new rate rows for the submitted purities.
     */
    public function store(StoreGoldRatesRequest $request): RedirectResponse
    {
        $this->authorize('manage', GoldRate::class);

        $rates = $request->ratesByPurity();
        $effectiveDate = (string) $request->validated('effective_date');

        $this->goldRateService->assertDeviationAccepted(
            $rates,
            $effectiveDate,
            (bool) $request->boolean('confirm_deviation')
        );

        $this->goldRateService->storeRates($rates, $effectiveDate, $request->user());

        return redirect()
            ->route('superadmin.gold-rates.index')
            ->with('success', 'Gold rates updated successfully.');
    }
}
