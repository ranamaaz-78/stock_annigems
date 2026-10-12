<?php

namespace App\Services\Reports;

use App\Repositories\Reports\AuditReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * AuditReportService
 *
 * Builds the Admin audit reports: user activity, gold rate history, and the
 * invoice/quotation document trail. The two movement logs live with their own
 * domains (InventoryReportService and StoneReportService) because they read
 * those modules' ledgers rather than audit_logs.
 *
 * Every actor is rendered through ReportFormatter::actor(), which shows the
 * role stored on the record rather than the actor's role today, and marks a
 * deactivated or deleted account as "(inactive)" instead of dropping it. An
 * audit trail that quietly loses its actors is not an audit trail.
 */
class AuditReportService
{
    public function __construct(
        private AuditReportRepository $audit,
    ) {}

    /**
     * Who did what, with a per-user summary above the entry list.
     */
    public function userActivity(ReportFilter $filter): ReportResult
    {
        $byUser = $this->audit->activityByUser($filter)->map(fn (array $row): array => [
            'actor' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
            'actions' => $row['actions'],
            'creates' => $row['creates'],
            'updates' => $row['updates'],
            'deletes' => $row['deletes'],
            'last_action_at' => $row['last_action_at'],
        ]);

        $entries = $this->audit->activity($filter, $this->limit())->map(fn (array $row): array => [
            'created_at' => $row['created_at'],
            'actor' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
            'action_label' => $row['action_label'],
            'model_type' => $row['model_type'],
            'ip_address' => $row['ip_address'],
        ]);

        return new ReportResult(
            key: 'user-activity',
            title: 'User Activity',
            summary: [
                $this->card('actions', 'Actions Recorded', ReportFormatter::count($byUser->sum('actions')), 'history'),
                $this->card('users', 'Users Active', ReportFormatter::count($byUser->count()), 'group'),
                $this->card('destructive', 'Voids, Cancellations & Deactivations',
                    ReportFormatter::count($byUser->sum('deletes')), 'delete',
                    hint: 'The actions worth reviewing'),
            ],
            rows: $byUser,
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
                'creates' => $byUser->sum('creates'),
                'updates' => $byUser->sum('updates'),
                'deletes' => $byUser->sum('deletes'),
                'actions' => $byUser->sum('actions'),
            ],
            meta: [
                'table_title' => 'Activity by user',
                'empty_title' => 'No recorded activity in this period',
                'extra_tables' => [
                    [
                        'title' => 'Individual entries, most recent first',
                        'columns' => [
                            ['key' => 'created_at', 'label' => 'When', 'format' => 'datetime'],
                            ['key' => 'actor', 'label' => 'User'],
                            ['key' => 'action_label', 'label' => 'Action'],
                            ['key' => 'model_type', 'label' => 'Record'],
                            ['key' => 'ip_address', 'label' => 'IP Address'],
                        ],
                        'rows' => $entries,
                    ],
                ],
            ],
        );
    }

    /**
     * Gold rate changes, with the movement against the previous rate.
     *
     * Rates are global and SuperAdmin-managed, so every branch sees the same
     * history — which is correct, because everyone priced against it.
     */
    public function goldRateHistory(ReportFilter $filter): ReportResult
    {
        $rows = $this->audit->goldRateHistory($filter, $this->limit())->map(fn (array $row): array => [
            'effective_date' => $row['effective_date'],
            'metal' => $row['metal'],
            'purity' => $row['purity'],
            'rate_per_gram' => $row['rate_per_gram'],
            'change' => $row['change'],
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
                    hint: $rows->isNotEmpty() ? $rows->first()['metal'].' '.$rows->first()['purity'].', '.$rows->first()['effective_date'] : null),
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
            meta: [
                'table_title' => 'Rate changes in this period',
                'money' => true,
                'empty_title' => 'No rate changes in this period',
                'empty_message' => 'Rates carry forward until a new one is set, so a quiet period is normal.',
            ],
        );
    }

    /**
     * The invoice and quotation trail: created, converted, cancelled, voided.
     */
    public function documentTrail(ReportFilter $filter): ReportResult
    {
        $rows = $this->audit->documentTrail($filter, $this->limit())->map(fn (array $row): array => [
            'created_at' => $row['created_at'],
            'document' => $row['document'],
            'reference' => $row['reference'],
            'action_label' => $row['action_label'],
            'actor' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
        ]);

        return new ReportResult(
            key: 'document-audit',
            title: 'Invoice & Quotation Audit Trail',
            summary: [
                $this->card('entries', 'Entries', ReportFormatter::count($rows->count()), 'history'),
                $this->card('invoices', 'Invoice Entries', ReportFormatter::count($rows->where('document', 'Invoice')->count()), 'receipt_long'),
                $this->card('quotations', 'Quotation Entries', ReportFormatter::count($rows->where('document', 'Quotation')->count()), 'request_quote'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'created_at', 'label' => 'When', 'format' => 'datetime'],
                ['key' => 'document', 'label' => 'Type'],
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'action_label', 'label' => 'Action'],
                ['key' => 'actor', 'label' => 'By'],
            ],
            meta: [
                'table_title' => 'Document trail',
                'empty_title' => 'No invoice or quotation activity in this period',
            ],
        );
    }

    /**
     * Row cap shared by every audit listing.
     */
    private function limit(): int
    {
        return (int) config('annigems.reports.per_page', 100);
    }

    /**
     * A KPI card in the shape the shared shell expects.
     *
     * @param  ?array{direction: string, label: string}  $trend
     * @return array<string, mixed>
     */
    private function card(string $key, string $label, string $value, ?string $icon = null, ?string $hint = null, ?array $trend = null): array
    {
        return compact('key', 'label', 'value', 'icon', 'hint', 'trend');
    }
}
