<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\ReportExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ReportFilterRequest;
use App\Models\Branch;
use App\Models\ReportExport;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\SuperAdmin\SuperAdminReportService;
use App\Support\AttributionFormatter;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SuperAdmin\ReportController
 *
 * The SuperAdmin reporting surface. Structurally the twin of the branch
 * controller, and deliberately separate from it rather than the same controller
 * behind a role check, for the reasons spec 7.4 gives:
 *
 * - It dispatches to SuperAdminReportService, whose dependencies are all
 *   aggregate-only services. There is no code path from here to a record.
 * - Its route parameters are a report slug and a branch uuid. No invoice,
 *   quotation, customer, item or stone uuid is accepted by any route it owns.
 * - Slugs are validated against the SuperAdmin catalog, which omits every
 *   record listing. Requesting an Admin-only report by URL 404s.
 */
class ReportController extends Controller
{
    public function __construct(
        private SuperAdminReportService $reports,
        private ReportExportService $exports,
    ) {}

    /**
     * Reports landing page.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewSuperAdminReports');

        return view('superadmin.reports.index', [
            'families' => ReportCatalog::superAdminFamilies(),
            'recentExports' => ReportExport::query()
                ->where('requested_by', $request->user()->id)
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * Render one aggregate report.
     */
    public function show(ReportFilterRequest $request, string $report): View
    {
        $this->authorize('viewSuperAdminReports');

        $definition = ReportCatalog::findSuperAdminReport($report);

        abort_if($definition === null, 404);

        $filter = $request->toFilter();
        $result = $this->decorate($this->reports->build($report, $filter), $filter, $request);

        return view('superadmin.reports.show', [
            'definition' => $definition,
            'result' => $result,
            'filter' => $filter,
            'tabs' => $this->tabs($definition['family'], $request->query()),
            'branches' => Branch::query()->orderBy('name')->get(['uuid', 'name']),
            'selectedBranch' => (string) $request->query('branch', ''),
            'canExport' => $request->user()->can('exportSuperAdminReports'),
        ]);
    }

    /**
     * Export the aggregate report currently on screen.
     */
    public function export(ReportFilterRequest $request, string $report): StreamedResponse|RedirectResponse|View
    {
        $this->authorize('exportSuperAdminReports');

        $definition = ReportCatalog::findSuperAdminReport($report);

        abort_if($definition === null, 404);

        $filter = $request->toFilter();
        $result = $this->decorate($this->reports->build($report, $filter), $filter, $request);

        if ($request->query('format') === 'pdf') {
            $this->exports->record($result, $filter, $request->user(), 'pdf')
                ->forceFill([
                    'status' => ReportExportStatus::STREAMED,
                    'row_count' => $result->rows->count(),
                    'completed_at' => now(),
                ])->save();

            return view('reports.print', ['result' => $result]);
        }

        $outcome = $this->exports->export($result, $filter, $request->user());

        if ($outcome instanceof ReportExport) {
            return redirect()
                ->route('superadmin.reports.index')
                ->with('success', __('This export is large, so it is being generated in the background. It will appear in Recent Exports below when it is ready.'));
        }

        return $outcome;
    }

    /**
     * Download a queued export.
     */
    public function download(Request $request, ReportExport $reportExport): mixed
    {
        $this->authorize('exportSuperAdminReports');

        // Ownership, not branch: a SuperAdmin export may legitimately have no
        // branch, so the requester is the only meaningful check.
        abort_unless($reportExport->requested_by === $request->user()->id, 403);
        abort_unless($reportExport->isDownloadable(), 404);

        return Storage::disk((string) config('annigems.reports.export.disk', 'local'))
            ->download($reportExport->file_path);
    }

    /**
     * Stamp the self-describing header onto a result.
     *
     * With no branch selected the header reads "All Branches", which is the
     * distinction that matters most on this surface: a consolidated total and
     * a single-branch total look identical without it.
     */
    private function decorate(ReportResult $result, ReportFilter $filter, ReportFilterRequest $request): ReportResult
    {
        $branchName = $filter->branchId === null
            ? __('All Branches')
            : (string) (Branch::query()->whereKey($filter->branchId)->value('name') ?? __('Branch'));

        return $result->withMeta([
            'header' => ReportExportService::header($result, $filter, $request->user(), $branchName),
            'branch_label' => $branchName,
            'range_label' => $filter->rangeLabel(),
            'generated_at' => AttributionFormatter::timestamp(now()),
            'range_slug' => $filter->from->toDateString().'-to-'.$filter->to->toDateString(),
        ]);
    }

    /**
     * Tabs for one report family, carrying the branch and date filters forward.
     *
     * @param  array<string, mixed>  $query
     * @return list<array{key: string, label: string, url: string}>
     */
    private function tabs(string $family, array $query): array
    {
        $reports = ReportCatalog::superAdminFamilies()[$family]['reports'] ?? [];
        $carried = array_intersect_key($query, array_flip(['branch', 'preset', 'from', 'to', 'grouping']));

        $tabs = [];

        foreach ($reports as $key => $report) {
            $tabs[] = [
                'key' => $key,
                'label' => $report['label'],
                'url' => route('superadmin.reports.show', array_merge(['report' => $key], $carried)),
            ];
        }

        return $tabs;
    }
}
