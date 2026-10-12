<?php

namespace App\Jobs;

use App\Enums\ReportDatePreset;
use App\Enums\ReportExportStatus;
use App\Enums\ReportGrouping;
use App\Enums\UserRole;
use App\Models\ReportExport;
use App\Services\Reports\AdminReportService;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\SuperAdmin\SuperAdminReportService;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportFilter;
use App\Support\Settings\RuntimeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GenerateReportExport
 *
 * Rebuilds a large report off the request cycle and writes it to disk.
 *
 * Only exports above the configured row threshold reach this job; everything
 * smaller streams inline. The job takes only the ReportExport id and rebuilds
 * the filter from the stored `filters` json rather than carrying a serialised
 * ReportFilter, which keeps the payload small and means a queued job cannot be
 * replayed against a different scope than the one that was recorded.
 *
 * The branch stored on the record is what scopes the rebuild, so a queued
 * export can never widen beyond what the requester was allowed to see.
 */
class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    /**
     * Generating a report is deterministic; a repeated failure is a real fault
     * rather than a transient one, so retries are kept low.
     */
    public int $tries = 2;

    public function __construct(
        public int $reportExportId,
    ) {}

    /**
     * Rebuild the report and write the CSV.
     *
     * Failures are recorded on the record itself as well as logged, so the user
     * who requested the export sees why it did not arrive instead of watching a
     * row sit at "Generating" forever.
     */
    public function handle(
        AdminReportService $admin,
        SuperAdminReportService $superAdmin,
        ReportExportService $exports,
    ): void {
        RuntimeSettings::apply();

        $record = ReportExport::query()->find($this->reportExportId);

        if ($record === null) {
            return;
        }

        $record->forceFill(['status' => ReportExportStatus::PROCESSING])->save();

        try {
            $filter = $this->filter($record);

            // Which builder runs is decided by the role stored on the record,
            // not by whether a branch was chosen. A SuperAdmin export narrowed
            // to one branch is still an aggregate export, and rebuilding it
            // through the Admin service would put record-level rows into a file
            // spec 7.4 says SuperAdmin may never receive.
            $isSuperAdminExport = $record->requested_by_role === UserRole::SUPERADMIN->value;

            $definition = $isSuperAdminExport
                ? ReportCatalog::findSuperAdminReport($record->report_key)
                : ReportCatalog::findAdminReport($record->report_key);

            if ($definition === null) {
                throw new \RuntimeException('Unknown report key '.$record->report_key.' for this surface.');
            }

            $result = $isSuperAdminExport
                ? $superAdmin->build($record->report_key, $filter)
                : $admin->build($record->report_key, $filter);

            // The self-describing header is rebuilt from the stored requester
            // and filters rather than carried on the payload, so the file says
            // who actually asked for it even if the job runs hours later.
            $requester = $record->requester;

            $exports->writeToDisk($record, $result->withMeta([
                'header' => $requester !== null
                    ? ReportExportService::header($result, $filter, $requester)
                    : [],
                'range_slug' => $filter->from->toDateString().'-to-'.$filter->to->toDateString(),
            ]));
        } catch (Throwable $exception) {
            Log::error('Report export failed', [
                'report_export_id' => $record->id,
                'report_key' => $record->report_key,
                'exception' => $exception->getMessage(),
            ]);

            $record->forceFill([
                'status' => ReportExportStatus::FAILED,
                'failure_reason' => 'The report could not be generated. Try a narrower date range.',
                'completed_at' => now(),
            ])->save();
        }
    }

    /**
     * Rebuild the immutable filter from the stored json.
     *
     * Values were validated when the export was requested; they are re-cast
     * here rather than trusted as-is so a hand-edited row cannot inject a
     * grouping expression or an invalid preset.
     */
    private function filter(ReportExport $record): ReportFilter
    {
        $stored = $record->filters ?? [];
        $timezone = (string) config('annigems.timezone', 'Asia/Karachi');

        return new ReportFilter(
            from: CarbonImmutable::parse((string) ($stored['from'] ?? 'today'), $timezone)->startOfDay(),
            to: CarbonImmutable::parse((string) ($stored['to'] ?? 'today'), $timezone)->endOfDay(),
            preset: ReportDatePreset::tryFrom((string) ($stored['preset'] ?? '')) ?? ReportDatePreset::CUSTOM,
            grouping: ReportGrouping::tryFrom((string) ($stored['grouping'] ?? '')) ?? ReportGrouping::DAY,
            branchId: $record->branch_id,
            options: is_array($stored['options'] ?? null) ? $stored['options'] : [],
        );
    }
}
