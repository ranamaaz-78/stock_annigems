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
            title: __('Performa Outstanding'),
            summary: [
                $this->card('outstanding', __('Still Out'), ReportFormatter::money($rows->sum('outstanding')), 'account_balance_wallet',
                    hint: __('At the price fixed on issue')),
                $this->card('creditors', __('Creditors Holding Goods'), ReportFormatter::count($rows->count()), 'groups'),
                $this->card('performas', __('Open Performas'), ReportFormatter::count($rows->sum('performas')), 'assignment'),
                $this->card('collected', __('Collected in Period'), ReportFormatter::money($settled['total']), 'payments',
                    hint: __(':count settlement invoices', ['count' => ReportFormatter::count($settled['count'])])),
            ],
            rows: $rows,
            columns: [
                ['key' => 'creditor_code', 'label' => __('Code')],
                ['key' => 'creditor', 'label' => __('Creditor')],
                ['key' => 'performas', 'label' => __('Performas'), 'align' => 'right', 'format' => 'count'],
                ['key' => 'issued', 'label' => __('Issued'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'settled', 'label' => __('Paid For'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'returned', 'label' => __('Returned'), 'align' => 'right', 'format' => 'money'],
                ['key' => 'outstanding', 'label' => __('Outstanding'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                ['key' => 'oldest_days', 'label' => __('Oldest (days)'), 'align' => 'right', 'format' => 'count'],
            ],
            totals: [
                'creditor' => __('Total'),
                'performas' => $rows->sum('performas'),
                'issued' => $rows->sum('issued'),
                'settled' => $rows->sum('settled'),
                'returned' => $rows->sum('returned'),
                'outstanding' => $rows->sum('outstanding'),
            ],
            chart: ReportResult::chart(__('Outstanding by creditor'), 'creditors', $rows->take(10)->map(fn (array $row): array => [
                'label' => $row['creditor'],
                'value' => $row['outstanding'],
                'display' => ReportFormatter::moneyCompact($row['outstanding']),
            ])->all()),
            meta: [
                'table_title' => __('Outstanding by creditor'),
                'live_snapshot' => true,
                'money' => true,
                'empty_title' => __('No goods are out on performa'),
                'empty_message' => __('Every performa has been paid for or returned.'),
                'extra_tables' => [
                    [
                        'title' => __('Open performas, oldest first'),
                        'columns' => [
                            ['key' => 'performa_number', 'label' => __('Performa')],
                            ['key' => 'performa_date', 'label' => __('Date')],
                            ['key' => 'creditor', 'label' => __('Creditor')],
                            ['key' => 'status', 'label' => __('Status')],
                            ['key' => 'issued', 'label' => __('Issued'), 'align' => 'right', 'format' => 'money'],
                            ['key' => 'outstanding', 'label' => __('Outstanding'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                            ['key' => 'age_days', 'label' => __('Age (days)'), 'align' => 'right', 'format' => 'count'],
                        ],
                        'rows' => $open,
                        'totals' => [
                            'performa_number' => __('Total'),
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
            title: __('Delivery Notes'),
            summary: [
                $this->card('issued', __('Waiting to Invoice'), ReportFormatter::money($waiting->sum('grand_total')), 'local_shipping',
                    hint: __(':count notes out now', ['count' => ReportFormatter::count($waiting->count())])),
                $this->card('invoiced', __('Invoiced in Period'), ReportFormatter::money($status('invoiced')['value']), 'receipt_long',
                    hint: __(':count notes', ['count' => ReportFormatter::count($status('invoiced')['notes'])])),
                $this->card('period', __('Issued in Period'), ReportFormatter::count($byStatus->sum('notes')), 'description',
                    hint: ReportFormatter::money($byStatus->sum('value'))),
                $this->card('cancelled', __('Cancelled'), ReportFormatter::count($status('cancelled')['notes']), 'cancel',
                    hint: ReportFormatter::money($status('cancelled')['value'])),
            ],
            rows: $rows,
            columns: [
                ['key' => 'delivery_note_number', 'label' => __('Delivery Note')],
                ['key' => 'delivery_date', 'label' => __('Date')],
                ['key' => 'customer', 'label' => __('Client')],
                ['key' => 'status_label', 'label' => __('Status')],
                ['key' => 'invoice_number', 'label' => __('Invoice')],
                ['key' => 'grand_total', 'label' => __('Total'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'delivery_note_number' => __('Total'),
                'grand_total' => $rows->sum('grand_total'),
            ],
            meta: [
                'table_title' => __('Delivery notes in the period'),
                'money' => true,
                'empty_title' => __('No delivery notes in this period'),
                'extra_tables' => [
                    [
                        'title' => __('By status'),
                        'columns' => [
                            ['key' => 'status_label', 'label' => __('Status')],
                            ['key' => 'notes', 'label' => __('Notes'), 'align' => 'right', 'format' => 'count'],
                            ['key' => 'value', 'label' => __('Value'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                        ],
                        'rows' => $byStatus->map(fn (array $row): array => $row + ['status_label' => ucfirst($row['status'])]),
                        'totals' => [
                            'status_label' => __('Total'),
                            'notes' => $byStatus->sum('notes'),
                            'value' => $byStatus->sum('value'),
                        ],
                    ],
                    [
                        'title' => __('Not yet invoiced, oldest first'),
                        'columns' => [
                            ['key' => 'delivery_note_number', 'label' => __('Delivery Note')],
                            ['key' => 'delivery_date', 'label' => __('Date')],
                            ['key' => 'customer', 'label' => __('Client')],
                            ['key' => 'grand_total', 'label' => __('Total'), 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
                            ['key' => 'age_days', 'label' => __('Age (days)'), 'align' => 'right', 'format' => 'count'],
                        ],
                        'rows' => $waiting,
                        'totals' => [
                            'delivery_note_number' => __('Total'),
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
