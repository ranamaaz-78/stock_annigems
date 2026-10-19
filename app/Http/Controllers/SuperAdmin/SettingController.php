<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\SettingGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SettingsHistoryRequest;
use App\Http\Requests\SuperAdmin\UpdateGlobalSettingsRequest;
use App\Models\SettingHistory;
use App\Services\SettingService;
use App\Support\Settings\SettingCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * SuperAdmin\SettingController
 *
 * Global settings and the change-history screen. Each group posts to its
 * own action so a mistake in Tax cannot rewrite Locale.
 */
class SettingController extends Controller
{
    public function __construct(private SettingService $settings) {}

    /**
     * Grouped global settings.
     */
    public function index(): View
    {
        $this->authorize('manageGlobalSettings');

        return view('superadmin.settings.index', $this->pageData(editable: true));
    }

    /**
     * Save one group.
     */
    public function update(UpdateGlobalSettingsRequest $request, string $group): RedirectResponse
    {
        $this->authorize('manageGlobalSettings');

        $settingGroup = $request->group();

        $raised = $this->settings->setGroup($settingGroup, $request->settingValues(), $request->user());

        $saved = $settingGroup->label().' settings saved.';
        if ($raised !== []) {
            $saved .= ' Starting numbers already issued were raised so the next code cannot collide.';
        }

        return redirect()
            ->route('superadmin.settings.index')
            ->with('success', $saved);
    }

    /**
     * Filterable change history.
     */
    public function history(SettingsHistoryRequest $request): View
    {
        $this->authorize('viewSettingsHistory');

        $query = SettingHistory::query()
            ->with(['changer', 'branch'])
            ->latest('id');

        if ($request->filled('group')) {
            $keys = array_column(SettingCatalog::forGroup(SettingGroup::from((string) $request->input('group'))), 'key');
            $query->whereIn('key', $keys);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        return view('superadmin.settings.history', [
            'rows' => $query->paginate(40)->withQueryString(),
            'groups' => SettingGroup::globalGroups(),
            'filters' => $request->only(['group', 'from', 'to']),
        ]);
    }

    /**
     * Shared view data for the SuperAdmin page and the Admin read-only mirror.
     *
     * @return array<string, mixed>
     */
    public function pageData(bool $editable): array
    {
        $groups = [];

        foreach (SettingGroup::globalGroups() as $group) {
            $definitions = SettingCatalog::forGroup($group);

            if ($definitions === []) {
                continue;
            }

            $values = $this->settings->getGroup($group);

            if ($group === SettingGroup::NUMBERING) {
                foreach (SettingCatalog::numberingStartMap() as $settingKey => $sequenceKey) {
                    $highest = $this->settings->highestIssued($sequenceKey);
                    $values[$settingKey] = max((int) $values[$settingKey], $highest + 1);
                }
            }

            $groups[] = [
                'group' => $group,
                'definitions' => $definitions,
                'values' => $values,
                'hints' => $group === SettingGroup::NUMBERING ? $this->settings->numberingHints() : [],
            ];
        }

        return [
            'groups' => $groups,
            'editable' => $editable,
        ];
    }
}
