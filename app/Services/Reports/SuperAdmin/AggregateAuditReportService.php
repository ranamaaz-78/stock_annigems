<?php

namespace App\Services\Reports\SuperAdmin;

use App\Repositories\Reports\AuditReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * AggregateAuditReportService
 *
 * SuperAdmin audit reporting: activity counts per user, and the global gold
 * rate history.
 *
 * The Admin surface also exposes an entry-by-entry log and the invoice /
 * quotation document trail. Both name records, so neither is built here — this
 * class can produce action counts and rate changes and nothing else.
 */
class AggregateAuditReportService extends AggregateReportService
{
    public function __construct(
        private AuditReportRepository $audit,
    ) {}

    /**
     * Action counts per user across the selected scope.
     */
    public function userActivity(ReportFilter $filter): ReportResult
    {
        $rows = $this->audit->activityByUser($filter)->map(fn (array $row): array => [
            'actor' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
            'creates' => $row['creates'],
            'updates' => $row['updates'],
            'deletes' => $row['deletes'],
            'actions' => $row['actions'],
            'last_action_at' => $row['last_action_at'],
        ]);

        return new ReportResult(
            key: 'user-activity',
            title: 'User Activity',
            summary: [
                $this->card('actions', 'Actions Recorded', ReportFormatter::count($rows->sum('actions')), 'history'),
                $this->card('users', 'Users Active', ReportFormatter::count($rows->count()), 'group'),
                $this->card('destructive', 'Voids, Cancellations & Deactivations',
                    ReportFormatter::count($rows->sum('deletes')), 'delete',
                    hint: 'The actions worth reviewing'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'actor', 'label' => 'User'],
                ['key' => 'creates', 'label' => 'Created', 'align' => 'right', 'format' => 'count'],
                ['key' => 'updates', 'label' => 'Updated', 'align' => 'right', 'format' => 'count'],
                ['key' => 'deletes', 'label' => 'Removed', 'align' => 'right', 'format' => 'count'],
                ['key' => 'actions', 'label' => 'Total Actions', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'last_action_at', 'label' => 'Last Action', 'format' => 'datetime'],
            ],
            totals: [
                'actor' => 'Total',
                'creates' => $rows->sum('creates'),
                'updates' => $rows->sum('updates'),
                'deletes' => $rows->sum('deletes'),
                'actions' => $rows->sum('actions'),
            ],
            meta: $this->meta([
                'table_title' => 'Activity by user',
                'empty_title' => 'No recorded activity in this period',
            ]),
        );
    }

    /**
     * Gold rate history. Rates are global and SuperAdmin-managed, so this is
     * the same set of rows regardless of the branch selector.
     */
    public function goldRateHistory(ReportFilter $filter): ReportResult
    {
        $rows = $this->audit->goldRateHistory($filter, (int) config('annigems.reports.per_page', 100))
            ->map(fn (array $row): array => [
                'effective_date' => $row['effective_date'],
                'metal' => $row['metal'],
                'purity' => $row['purity'],
                'change' => $row['change'],
                'rate_per_gram' => $row['rate_per_gram'],
                'set_by' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
            ]);

        return new ReportResult(
            key: 'gold-rate-history',
            title: 'Gold Rate History',
            summary: [
                $this->card('changes', 'Rate Changes', ReportFormatter::count($rows->count()), 'trending_up'),
                $this->card('purities', 'Purities Updated', ReportFormatter::count($rows->pluck('purity')->unique()->count()), 'diamond'),
                $this->card('latest', 'Most Recent Rate',
                    $rows->isNotEmpty() ? ReportFormatter::money($rows->first()['rate_per_gram']) : '—', 'payments',
                    hint: $rows->isNotEmpty() ? $rows->first()['metal'].' '.$rows->first()['purity'] : null),
            ],
            rows: $rows,
            columns: [
                ['key' => 'effective_date', 'label' => 'Effective'],
                ['key' => 'metal', 'label' => 'Metal'],
                ['key' => 'purity', 'label' => 'Purity'],
                ['key' => 'change', 'label' => 'Change', 'align' => 'right', 'format' => 'money'],
                ['key' => 'rate_per_gram', 'label' => 'Rate / gram', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'set_by', 'label' => 'Set By'],
            ],
            meta: $this->meta([
                'table_title' => 'Rate changes in this period',
                'money' => true,
                'global_data' => true,
                'empty_title' => 'No rate changes in this period',
            ]),
        );
    }
}
