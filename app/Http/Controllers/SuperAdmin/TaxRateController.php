<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SaveTaxRateRequest;
use App\Models\TaxRate;
use App\Services\TaxRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * TaxRateController
 *
 * SuperAdmin management of the two tax lists: IVA and Recargo de equivalencia.
 * Documents keep a snapshot of the rate they used, so nothing here rewrites
 * an invoice or quotation that already exists.
 */
class TaxRateController extends Controller
{
    public function __construct(private TaxRateService $taxRates) {}

    /**
     * Both lists on one screen.
     */
    public function index(): View
    {
        return view('superadmin.tax-rates.index', [
            'iva' => $this->taxRates->all(TaxRate::KIND_IVA),
            'recargo' => $this->taxRates->all(TaxRate::KIND_RECARGO),
            'taxEnabled' => (bool) setting('tax_enabled'),
        ]);
    }

    /**
     * Add a rate to one of the lists.
     */
    public function store(SaveTaxRateRequest $request): RedirectResponse
    {
        $rate = $this->taxRates->create($request->validated());

        return back()->with('success', __(':name added.', ['name' => $rate->name]));
    }

    /**
     * Rename or re-rate.
     */
    public function update(SaveTaxRateRequest $request, TaxRate $taxRate): RedirectResponse
    {
        $this->taxRates->update($taxRate, $request->validated());

        return back()->with('success', __(':name updated.', ['name' => $taxRate->name]));
    }

    /**
     * Make an IVA rate the one new documents start with.
     */
    public function makeDefault(TaxRate $taxRate): RedirectResponse
    {
        $this->taxRates->setDefault($taxRate);

        return back()->with('success', __(':name is now the default IVA rate.', ['name' => $taxRate->name]));
    }

    /**
     * Turn a rate on or off.
     */
    public function toggle(TaxRate $taxRate): RedirectResponse
    {
        $rate = $this->taxRates->toggle($taxRate);

        return back()->with('success', $rate->is_active ? __(':name is on.', ['name' => $rate->name]) : __(':name is off.', ['name' => $rate->name]));
    }

    /**
     * Remove a rate from the list.
     */
    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        $name = $taxRate->name;
        $this->taxRates->delete($taxRate);

        return back()->with('success', __(':name deleted.', ['name' => $name]));
    }
}