<?php

namespace App\Http\Controllers\Branch;

use App\Enums\SettingGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\UpdateBranchContactRequest;
use App\Http\Requests\Branch\UpdateBranchSettingsRequest;
use App\Services\BranchService;
use App\Services\BranchSettingService;
use App\Services\GoldRateService;
use App\Support\Settings\BranchSettingCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Branch\SettingController
 *
 * Admin-owned local preferences plus a read-only mirror of SuperAdmin
 * settings. Contact details edit the Branch record; print and footers
 * write branch_settings.
 */
class SettingController extends Controller
{
    public function __construct(
        private BranchSettingService $branchSettings,
        private BranchService $branches,
        private GoldRateService $goldRates,
    ) {}

    /**
     * Branch settings: contact, print, footers.
     */
    public function index(): View
    {
        $this->authorize('viewBranchSettings');

        $user = request()->user();
        $branch = $user->branch;
        $editable = $user->can('manageBranchSettings');

        return view('branch.settings.index', [
            'branch' => $branch,
            'editable' => $editable,
            'print' => $this->branchSettings->getGroup($branch->id, SettingGroup::PRINT),
            'printDefinitions' => BranchSettingCatalog::forGroup(SettingGroup::PRINT),
            'footers' => $this->branchSettings->getGroup($branch->id, SettingGroup::INVOICE),
            'footerDefinitions' => BranchSettingCatalog::forGroup(SettingGroup::INVOICE),
        ]);
    }

    /**
     * Save contact details onto the branch record.
     */
    public function updateContact(UpdateBranchContactRequest $request): RedirectResponse
    {
        $this->authorize('manageBranchSettings');

        $this->branches->updateContact($request->user()->branch, $request->validated(), $request->user());

        return redirect()
            ->route('branch.settings.index')
            ->with('success', 'Branch contact details saved.');
    }

    /**
     * Save print preferences or footer notes.
     */
    public function update(UpdateBranchSettingsRequest $request, string $group): RedirectResponse
    {
        $this->authorize('manageBranchSettings');

        $this->branchSettings->setGroup(
            (int) $request->user()->branch_id,
            $request->group(),
            $request->settingValues(),
            $request->user()
        );

        return redirect()
            ->route('branch.settings.index')
            ->with('success', $request->group()->label().' saved.');
    }

    /**
     * Read-only SuperAdmin settings, so an Admin can see why a barcode
     * did not generate or what the discount ceiling is.
     */
    public function global(): View
    {
        $this->authorize('viewGlobalSettings');

        $page = app(\App\Http\Controllers\SuperAdmin\SettingController::class)->pageData(false);

        return view('branch.settings.global', array_merge($page, [
            'currentRates' => $this->goldRates->currentRates(),
        ]));
    }
}
