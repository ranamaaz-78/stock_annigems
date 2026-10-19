<?php

namespace App\Http\Controllers\Branch;

use App\Enums\JewelleryItemMovementType;
use App\Enums\ReportExportStatus;
use App\Enums\StoneMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\ReportFilterRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\JewelleryItem;
use App\Models\Purity;
use App\Models\ReportExport;
use App\Models\Stone;
use App\Models\User;
use App\Services\Reports\AdminReportService;
use App\Services\Reports\ReportExportService;
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
 * ReportController
 *
 * The whole branch (Admin) reporting surface runs through three routes: a
 * landing page, one report page, and one export endpoint. Which report renders
 * is decided by a slug validated against ReportCatalog, so adding a report
 * needs no route change.
 *
 * Authorisation happens twice on every request. ReportFilterRequest::authorize()
 * checks the ability before validation runs, and each method re-authorises
 * explicitly — belt and braces, because a report is the one screen where the
 * whole point is aggregated commercial data.
 *
 * The controller holds no report logic. It resolves the filter, dispatches to
 * AdminReportService, stamps the self-describing header, and renders.
 */
class ReportController extends Controller
{
    public function __construct(
        private AdminReportService $reports,
        private ReportExportService $exports,
    ) {}

    /**
     * Reports landing page: every family and report the Admin may open.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAdminReports');

        return view('branch.reports.index', [
            'families' => ReportCatalog::adminFamilies(),
            'recentExports' => ReportExport::query()
                ->where('branch_id', $request->user()->branch_id)
                ->where('requested_by', $request->user()->id)
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * Render one report.
     *
     * An unknown slug 404s here rather than reaching a service, which is what
     * makes the single `{report}` route parameter safe.
     */
    public function show(ReportFilterRequest $request, string $report): View
    {
        $this->authorize('viewAdminReports');

        $definition = ReportCatalog::findAdminReport($report);

        abort_if($definition === null, 404);

        $filter = $request->toFilter();
        $result = $this->decorate($this->reports->build($report, $filter), $filter, $request);

        return view('branch.reports.show', [
            'definition' => $definition,
            'result' => $result,
            'filter' => $filter,
            'tabs' => $this->tabs($definition['family'], $request->query()),
            'options' => $this->filterOptions($definition),
            'canExport' => $request->user()->can('exportAdminReports'),
        ]);
    }

    /**
     * Export the report currently on screen.
     *
     * The same validated filter drives the export as drove the screen, so a
     * downloaded file always matches what the operator was looking at.
     * CSV streams (or queues, when large); PDF is the print-optimised page,
     * following the convention already used by invoice and quotation printing.
     */
    public function export(ReportFilterRequest $request, string $report): StreamedResponse|RedirectResponse|View
    {
        $this->authorize('exportAdminReports');

        $definition = ReportCatalog::findAdminReport($report);

        abort_if($definition === null, 404);

        $filter = $request->toFilter();
        $result = $this->decorate($this->reports->build($report, $filter), $filter, $request);

        if ($request->query('format') === 'pdf') {
            // Recorded even though nothing is written to disk: an owner asking
            // "who printed the March figures" deserves an answer.
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
                ->route('branch.reports.index')
                ->with('success', __('This export is large, so it is being generated in the background. It will appear in Recent Exports below when it is ready.'));
        }

        return $outcome;
    }

    /**
     * Download a queued export once its job has finished.
     */
    public function download(Request $request, ReportExport $reportExport): mixed
    {
        $this->authorize('exportAdminReports');

        // An export belongs to the branch and the person who asked for it. This
        // is the only ownership check that matters, because the file already
        // contains that branch's figures.
        abort_unless(
            $reportExport->branch_id === $request->user()->branch_id
                && $reportExport->requested_by === $request->user()->id,
            403
        );

        abort_unless($reportExport->isDownloadable(), 404);

        return Storage::disk((string) config('annigems.reports.export.disk', 'local'))
            ->download($reportExport->file_path);
    }

    /**
     * Stamp the meta every report screen and export needs.
     *
     * The header is built once here and travels with the result to the screen,
     * the CSV and the print page, which is why all three can never disagree
     * about which branch or which range they are describing.
     */
    private function decorate(ReportResult $result, ReportFilter $filter, ReportFilterRequest $request): ReportResult
    {
        $branchName = $request->user()->branch?->name;

        return $result->withMeta([
            'header' => ReportExportService::header($result, $filter, $request->user(), $branchName),
            'branch_label' => $branchName ?? __('Branch'),
            'range_label' => $filter->rangeLabel(),
            'generated_at' => AttributionFormatter::timestamp(now()),
            'range_slug' => $filter->from->toDateString().'-to-'.$filter->to->toDateString(),
        ]);
    }

    /**
     * Tabs for one report family, carrying the current filters forward so
     * switching report keeps the date range the operator chose.
     *
     * @param  array<string, mixed>  $query
     * @return list<array{key: string, label: string, url: string}>
     */
    private function tabs(string $family, array $query): array
    {
        $reports = ReportCatalog::adminFamilies()[$family]['reports'] ?? [];
        $carried = array_intersect_key($query, array_flip(['preset', 'from', 'to', 'grouping']));

        $tabs = [];

        foreach ($reports as $key => $report) {
            $tabs[] = [
                'key' => $key,
                'label' => $report['label'],
                'url' => route('branch.reports.show', array_merge(['report' => $key], $carried)),
            ];
        }

        return $tabs;
    }

    /**
     * Option lists for the secondary filter controls a report declares.
     *
     * Only the lists a report actually uses are queried — a sales summary has
     * no reason to load every stone in the branch.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function filterOptions(array $definition): array
    {
        $needed = $definition['filters'];
        $options = [];

        // Two different ledgers answer to "movement type", so the list follows
        // the report rather than offering the union of both enums.
        if (in_array('movement_type', $needed, true)) {
            $options['movement_types'] = $definition['family'] === 'stones' || $definition['key'] === 'stone-movement-log'
                ? StoneMovementType::cases()
                : JewelleryItemMovementType::cases();
        }

        // Stone, JewelleryItem and User carry BranchScope, so these lists are
        // already narrowed to the Admin's own branch without an explicit where.
        if (in_array('category', $needed, true)) {
            $options['categories'] = Category::query()->orderBy('name')->get(['uuid', 'name']);
        }

        if (in_array('purity', $needed, true)) {
            $options['purities'] = Purity::query()->orderBy('name')->get(['uuid', 'name']);
        }

        if (in_array('staff', $needed, true)) {
            $options['staff'] = User::query()->orderBy('name')->get(['uuid', 'name']);
        }

        if (in_array('customer', $needed, true)) {
            $options['customers'] = Customer::query()->orderBy('name')->limit(500)->get(['uuid', 'name', 'phone']);
        }

        if (in_array('stone', $needed, true)) {
            $options['stones'] = Stone::query()->orderBy('name')->get(['uuid', 'name', 'stone_code']);
        }

        if (in_array('item', $needed, true)) {
            $options['items'] = JewelleryItem::query()->orderBy('item_code')->limit(500)->get(['uuid', 'item_code', 'name']);
        }

        return $options;
    }
}
