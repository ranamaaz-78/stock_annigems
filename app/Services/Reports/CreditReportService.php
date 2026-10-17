<?php

namespace App\Services\Reports;

use App\Repositories\Reports\CreditReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * CreditReportService
 *
 * Goods that have left the shelf without being sold yet: what creditors still
 * owe on performas, and delivery notes not yet invoiced. Neither is revenue.
 * Performa money is the value fixed when each line was issued; delivery note
 * money is the note's own total.
 */
class CreditReportService
{
    public function __construct(
        private CreditReportRepository $credit,
    ) {}

    /**
     * What creditors still hold on open and part-paid performas.
     */
    public function performaOutstanding(ReportFilter $filter): ReportResult
    {
        $limit = (int) config('annigems.reports.per_page', 100);
        $rows = $this->credit->outstandingByCreditor($filter->branchId);
        $open = $this->credit->openPerformas($filter->branchId, $limit);
        $settled = $this->credit->settledInPeriod($filter);

        return new ReportResult(
            key: 'performa-outstanding',
            title: 'Performa Outstanding',
            summary: [
                $this->card('outstanding', 'Still Out', ReportFormatter::money($rows->sum('outstanding')), 'account_balance_wallet',
                    hint: 'At the price fixed on issue'),
                $this->card('creditors', 'Creditors Holding Goods', ReportFormatter::count($rows->count()), 'groups'),
                $this->card('performas', 'Open Performas', ReportFormatter::count($rows->sum('performas')), 'assignment'),
                $this->card('collected', 'Collected in Period', ReportFormatter::money($settled['total']), 'payments',
                    hint: ReportFormatter::count($settled['count']).' settlement invoices'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'creditor_code', 'label' => 'Code'],
                ['key' => 'creditor', 'label' => 'Creditor'],
                ['key' => 'performas', 'label' => 'Performas', 'align' => 'right', 'format' => 'count'],
                ['key' => 'issued', 'label' => 'Issued', 'align' => 'right', 'format' => 'money'],
                ['key' => 'settled', 'label' => 'Paid For', 'align' => 'right', 'format' => 'money'],
                ['key' => 'returned', 'label' => 'Returned', 'align' => 'right', 'format' => 'money'],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'oldest_days', 'label' => 'Oldest (days)', 'align' => 'right', 'format' => 'count'],
            ],
            totals: [
                'creditor' => 'Total',
                'performas' => $rows->sum('performas'),
                'issued' => $rows->sum('issued'),
                'settled' => $rows->sum('settled'),
                'returned' => $rows->sum('returned'),
                'outstanding' => $rows->sum('outstanding'),
            ],
            chart: ReportResult::chart('Outstanding by creditor', 'creditors', $rows->take(10)->map(fn (array $row): array => [
                'label' => $row['creditor'],
                'value' => $row['outstanding'],
                'display' => ReportFormatter::moneyCompact($row['outstanding']),
            ])->all()),
            meta: [
                'table_title' => 'Outstanding by creditor',
                'live_snapshot' => true,
                'money' => true,
                'empty_title' => 'No goods are out on performa',
                'empty_message' => 'Every performa has been paid for or returned.',
                'extra_tables' => [
                    [
                        'title' => 'Open performas, oldest first',
                        'columns' => [
                            ['key' => 'performa_number', 'label' => 'Performa'],
                            ['key' => 'performa_date', 'label' => 'Date'],
                            ['key' => 'creditor', 'label' => 'Creditor'],
                            ['key' => 'status', 'label' => 'Status'],
                            ['key' => 'issued', 'label' => 'Issued', 'align' => 'right', 'format' => 'money'],
                            ['key' => 'outstanding', 'label' => 'Outstanding', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                            ['key' => 'age_days', 'label' => 'Age (days)', 'align' => 'right', 'format' => 'count'],
                        ],
                        'rows' => $open,
                        'totals' => [
                            'performa_number' => 'Total',
                            'issued' => $open->sum('issued'),
                            'outstanding' => $open->sum('outstanding'),
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Delivery notes in the period, by status, and the ones not yet invoiced.
     */
    public function deliveryNotes(ReportFilter $filter): ReportResult
    {
        $limit = (int) config('annigems.reports.per_page', 100);
        $rows = $this->credit->deliveryNotes($filter, $limit)->map(fn (array $row): array => $row + [
            'status_label' => ucfirst($row['status']),
        ]);
        $byStatus = $this->credit->deliveryNotesByStatus($filter);
        $waiting = $this->credit->uninvoicedNotes($filter->branchId, $limit);

        $status = fn (string $key): array => $byStatus->firstWhere('status', $key) ?? ['notes' => 0, 'value' => 0.0];

        return new ReportResult(
            key: 'delivery-notes',
            title: 'Delivery Notes',
            summary: [
                $this->card('issued', 'Waiting to Invoice', ReportFormatter::money($waiting->sum('grand_total')), 'local_shipping',
                    hint: ReportFormatter::count($waiting->count()).' notes out now'),
                $this->card('invoiced', 'Invoiced in Period', ReportFormatter::money($status('invoiced')['value']), 'receipt_long',
                    hint: ReportFormatter::count($status('invoiced')['notes']).' notes'),
                $this->card('period', 'Issued in Period', ReportFormatter::count($byStatus->sum('notes')), 'description',
                    hint: ReportFormatter::money($byStatus->sum('value'))),
                $this->card('cancelled', 'Cancelled', ReportFormatter::count($status('cancelled')['notes']), 'cancel',
                    hint: ReportFormatter::money($status('cancelled')['value'])),
            ],
            rows: $rows,
            columns: [
                ['key' => 'delivery_note_number', 'label' => 'Delivery Note'],
                ['key' => 'delivery_date', 'label' => 'Date'],
                ['key' => 'customer', 'label' => 'Client'],
                ['key' => 'status_label', 'label' => 'Status'],
                ['key' => 'invoice_number', 'label' => 'Invoice'],
                ['key' => 'grand_total', 'label' => 'Total', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'delivery_note_number' => 'Total',
                'grand_total' => $rows->sum('grand_total'),
            ],
            meta: [
                'table_title' => 'Delivery notes in the period',
                'money' => true,
                'empty_title' => 'No delivery notes in this period',
                'extra_tables' => [
                    [
                        'title' => 'By status',
                        'columns' => [
                            ['key' => 'status_label', 'label' => 'Status'],
                            ['key' => 'notes', 'label' => 'Notes', 'align' => 'right', 'format' => 'count'],
                            ['key' => 'value', 'label' => 'Value', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $byStatus->map(fn (array $row): array => $row + ['status_label' => ucfirst($row['status'])]),
                        'totals' => [
                            'status_label' => 'Total',
                            'notes' => $byStatus->sum('notes'),
                            'value' => $byStatus->sum('value'),
                        ],
                    ],
                    [
                        'title' => 'Not yet invoiced, oldest first',
                        'columns' => [
                            ['key' => 'delivery_note_number', 'label' => 'Delivery Note'],
                            ['key' => 'delivery_date', 'label' => 'Date'],
                            ['key' => 'customer', 'label' => 'Client'],
                            ['key' => 'grand_total', 'label' => 'Total', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                            ['key' => 'age_days', 'label' => 'Age (days)', 'align' => 'right', 'format' => 'count'],
                        ],
                        'rows' => $waiting,
                        'totals' => [
                            'delivery_note_number' => 'Total',
                            'grand_total' => $waiting->sum('grand_total'),
                        ],
                    ],
                ],
            ],
        );
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
