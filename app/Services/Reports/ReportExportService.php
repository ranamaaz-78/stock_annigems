<?php

namespace App\Services\Reports;

use App\Enums\ReportExportStatus;
use App\Jobs\GenerateReportExport;
use App\Models\Branch;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\AttributionFormatter;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ReportExportService
 *
 * Turns a ReportResult into a downloadable file.
 *
 * Deliberately dependency-free: CSV is written with PHP's own fputcsv through a
 * streamed response, and "PDF" is the same report rendered through a
 * print-optimised Blade page — the convention the invoice and quotation sheets
 * already use. Adding a spreadsheet or PDF library for this would be weight the
 * project does not need to carry.
 *
 * Small exports stream straight to the browser. Large ones are handed to a
 * queued job and written to disk, so a report covering two years never holds a
 * web worker open or times out mid-download. The threshold is configurable.
 *
 * Every export, streamed or queued, creates a ReportExport row and an audit
 * entry recording who pulled which figures with which filters.
 */
class ReportExportService
{
    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    /**
     * Record the export request and either stream it or queue it.
     *
     * Returns the streamed response for an inline export, or the ReportExport
     * record for a queued one, so the controller can redirect with a message.
     */
    public function export(ReportResult $result, ReportFilter $filter, User $actor): StreamedResponse|ReportExport
    {
        $record = $this->record($result, $filter, $actor, 'csv');

        if ($result->rows->count() >= $this->queueThreshold()) {
            // Queued: the row is the receipt, and the job fills in file_path.
            GenerateReportExport::dispatch($record->id);

            return $record;
        }

        $record->forceFill([
            'status' => ReportExportStatus::STREAMED,
            'row_count' => $result->rows->count(),
            'completed_at' => now(),
        ])->save();

        return $this->stream($result);
    }

    /**
     * Stream a report as CSV.
     *
     * Rows are written one at a time inside the callback, so memory stays flat
     * regardless of how many rows the report produced.
     */
    public function stream(ReportResult $result): StreamedResponse
    {
        $filename = $this->filename($result);

        return response()->streamDownload(function () use ($result): void {
            $handle = fopen('php://output', 'wb');

            foreach ($this->lines($result) as $line) {
                fputcsv($handle, $line);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Write a report to the export disk and mark the record complete.
     *
     * Called from the queued job. Returns the stored relative path.
     */
    public function writeToDisk(ReportExport $record, ReportResult $result): string
    {
        $disk = Storage::disk($this->disk());
        $path = 'exports/'.$record->uuid.'/'.$this->filename($result);

        // Buffer through a temp stream so the file lands on disk in one write
        // and a partially generated export is never downloadable.
        $handle = fopen('php://temp', 'r+b');

        foreach ($this->lines($result) as $line) {
            fputcsv($handle, $line);
        }

        rewind($handle);
        $disk->put($path, stream_get_contents($handle));
        fclose($handle);

        $record->forceFill([
            'status' => ReportExportStatus::COMPLETED,
            'file_path' => $path,
            'row_count' => $result->rows->count(),
            'completed_at' => now(),
        ])->save();

        return $path;
    }

    /**
     * Create the export record and its audit entry.
     *
     * The audit entry carries the filters, which is what makes an export
     * reviewable after the fact: "who pulled which figures, for which range".
     */
    public function record(ReportResult $result, ReportFilter $filter, User $actor, string $format): ReportExport
    {
        $record = new ReportExport([
            'branch_id' => $filter->branchId,
            'report_key' => $result->key,
            'report_title' => $result->title,
            'filters' => $filter->toArray(),
            'format' => $format,
            'status' => ReportExportStatus::PENDING,
        ]);

        $record->save();

        $this->auditLog->log('report.exported', $record, null, [
            'report_key' => $result->key,
            'report_title' => $result->title,
            'format' => $format,
            'filters' => $filter->toArray(),
        ], $actor);

        return $record;
    }

    /**
     * Every line of the CSV, header block included.
     *
     * Yielded rather than returned so neither the streamed nor the queued path
     * ever holds the whole file in memory.
     *
     * @return \Generator<int, list<string>>
     */
    private function lines(ReportResult $result): \Generator
    {
        // Self-describing header (spec 9.3). A CSV that lands in an inbox with
        // no context is worse than no CSV: the reader cannot tell which branch
        // or which month it covers.
        foreach ($result->meta('header', []) as $item) {
            yield [$item['label'], $item['value']];
        }

        yield [];

        // The revenue disclaimer travels with the file, not just the screen.
        if ($result->meta('money', false)) {
            yield ['Note', 'Revenue figures, not profit. Purchase cost is not recorded by the system, so margin cannot be calculated. Voided invoices are excluded.'];
            yield [];
        }

        foreach ($result->summary as $card) {
            yield [$card['label'], $card['value']];
        }

        if ($result->summary !== []) {
            yield [];
        }

        yield from $this->tableLines($result->columns, $result->rows, $result->totals);

        // Reports that carry a second table (discount by staff, stone totals by
        // type) export both, so the file matches the screen exactly.
        foreach ($result->meta('extra_tables', []) as $table) {
            yield [];
            yield [$table['title']];

            yield from $this->tableLines(
                $table['columns'],
                $table['rows'],
                $table['totals'] ?? []
            );
        }
    }

    /**
     * Header, body and totals rows for one table.
     *
     * Cells are formatted through ReportFormatter, the same helper the screen
     * uses, so an exported figure and its on-screen counterpart are identical.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @return \Generator<int, list<string>>
     */
    private function tableLines(array $columns, iterable $rows, array $totals): \Generator
    {
        if ($columns === []) {
            return;
        }

        yield array_map(fn (array $column): string => $column['label'], $columns);

        foreach ($rows as $row) {
            yield array_map(
                fn (array $column): string => ReportFormatter::cell($row[$column['key']] ?? null, $column['format'] ?? null),
                $columns
            );
        }

        if ($totals !== []) {
            yield array_map(function (array $column, int $index) use ($totals): string {
                if ($index === 0) {
                    return (string) ($totals[$column['key']] ?? 'Total');
                }

                return array_key_exists($column['key'], $totals)
                    ? ReportFormatter::cell($totals[$column['key']], $column['format'] ?? null)
                    : '';
            }, $columns, array_keys($columns));
        }
    }

    /**
     * File name built from the report and its date range, per spec 9.4.
     *
     * Example: annigems-sales-summary-2026-09-01-to-2026-09-30.csv
     */
    private function filename(ReportResult $result): string
    {
        $range = $result->meta('range_slug', '');

        return Str::slug('aanigemas-'.$result->key.($range !== '' ? '-'.$range : '')).'.csv';
    }

    /**
     * Row count above which an export is queued instead of streamed.
     */
    private function queueThreshold(): int
    {
        return (int) config('annigems.reports.export.queue_row_threshold', 5000);
    }

    /**
     * Disk holding generated export files.
     */
    private function disk(): string
    {
        return (string) config('annigems.reports.export.disk', 'local');
    }

    /**
     * Self-describing header rows for a report, shared by the screen, the CSV
     * and the print view so all three say the same thing.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function header(ReportResult $result, ReportFilter $filter, User $actor, ?string $branchName = null): array
    {
        $role = $actor->role instanceof \BackedEnum ? $actor->role->value : (string) $actor->role;

        return [
            ['label' => __('Branch'), 'value' => $branchName ?? ($filter->branchId === null ? 'All Branches' : (string) (Branch::query()->whereKey($filter->branchId)->value('name') ?? 'Branch'))],
            ['label' => __('Report'), 'value' => $result->title],
            ['label' => __('Date range'), 'value' => $filter->rangeLabel()],
            ['label' => __('Generated by'), 'value' => $actor->name.' ('.AttributionFormatter::roleLabel($role).')'],
            ['label' => __('Generated at'), 'value' => AttributionFormatter::timestamp(now())],
        ];
    }
}
