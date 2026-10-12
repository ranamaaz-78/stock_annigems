<?php

namespace App\Services\Reports;

use App\Repositories\Reports\QuotationReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * QuotationReportService
 *
 * Builds the Admin quotation reports: how much quoted business actually closes,
 * what is still open, how long customers take to decide, and what was lost.
 *
 * Quoted value is never revenue. A quotation is an offer; only a completed
 * invoice is money. Every value column here is labelled "quoted" for that
 * reason, and nothing in this service feeds a revenue figure.
 */
class QuotationReportService
{
    public function __construct(
        private QuotationReportRepository $quotations,
    ) {}

    /**
     * Funnel: raised, converted, cancelled, and the conversion rate.
     */
    public function funnel(ReportFilter $filter): ReportResult
    {
        $totals = $this->quotations->funnelTotals($filter);
        $previous = $this->quotations->funnelTotals($filter->previousPeriod());
        $rows = $this->quotations->funnel($filter);

        return new ReportResult(
            key: 'quotation-funnel',
            title: 'Quotation Funnel',
            summary: [
                $this->card('total', 'Quotations Raised', ReportFormatter::count($totals['total']), 'request_quote',
                    trend: ReportFormatter::trend($totals['total'], $previous['total'])),
                $this->card('converted', 'Converted to Invoice', ReportFormatter::count($totals['converted']), 'task_alt',
                    hint: ReportFormatter::money($totals['converted_value']).' billed'),
                $this->card('conversion_rate', 'Conversion Rate', ReportFormatter::percent($totals['conversion_rate']), 'percent',
                    trend: ReportFormatter::trend($totals['conversion_rate'], $previous['conversion_rate'])),
                $this->card('open', 'Still Open', ReportFormatter::count($totals['draft']), 'pending',
                    hint: ReportFormatter::money($totals['total_value']).' quoted in total'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'label', 'label' => 'Status'],
                ['key' => 'quotations', 'label' => 'Quotations', 'align' => 'right', 'format' => 'count'],
                ['key' => 'share', 'label' => 'Share', 'align' => 'right', 'format' => 'percent'],
                ['key' => 'value', 'label' => 'Quoted Value', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'label' => 'Total',
                'quotations' => $totals['total'],
                'value' => $totals['total_value'],
            ],
            chart: ReportResult::chart('Quotations by status', 'quotations', $rows->map(fn (array $row): array => [
                'label' => $row['label'],
                'value' => $row['quotations'],
                'display' => ReportFormatter::count($row['quotations']),
            ])->all()),
            meta: [
                'table_title' => 'Funnel breakdown',
                'money' => true,
                'empty_title' => 'No quotations raised in this period',
            ],
        );
    }

    /**
     * Open drafts with their age.
     *
     * Not date-filtered on purpose: the useful question is "what is outstanding
     * right now", and a draft raised before the selected range is exactly the
     * one most in need of chasing. Age matters because an old draft was priced
     * against a gold rate that has since moved.
     */
    public function pending(ReportFilter $filter): ReportResult
    {
        $totals = $this->quotations->pendingTotals($filter->branchId);
        $rows = $this->quotations->pendingDrafts($filter->branchId, (int) config('annigems.reports.per_page', 100));

        $stale = $rows->filter(fn (array $row): bool => $row['age_days'] >= 7)->count();

        return new ReportResult(
            key: 'pending-quotations',
            title: 'Pending Quotations',
            summary: [
                $this->card('pending', 'Open Drafts', ReportFormatter::count($totals['count']), 'pending_actions'),
                $this->card('value', 'Quoted Value Outstanding', ReportFormatter::money($totals['value']), 'request_quote'),
                $this->card('stale', 'Older Than 7 Days', ReportFormatter::count($stale), 'schedule',
                    hint: 'Priced at a gold rate that has since moved'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'quotation_number', 'label' => 'Quotation'],
                ['key' => 'quotation_date', 'label' => 'Raised'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'age_days', 'label' => 'Age (days)', 'align' => 'right', 'format' => 'count'],
                ['key' => 'value', 'label' => 'Quoted Value', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'quotation_number' => 'Total',
                'value' => $rows->sum('value'),
            ],
            meta: [
                'table_title' => 'Open drafts, oldest first',
                'money' => true,
                'ignores_date_range' => true,
                'empty_title' => 'No quotations are waiting',
                'empty_message' => 'Every quotation raised has been converted or cancelled.',
            ],
        );
    }

    /**
     * How long customers take to decide.
     *
     * There is no converted_at column on a quotation, so the conversion moment
     * is taken from the invoice it produced.
     */
    public function timeToConvert(ReportFilter $filter): ReportResult
    {
        $summary = $this->quotations->timeToConvertSummary($filter);
        $rows = $this->quotations->timeToConvertRows($filter);

        return new ReportResult(
            key: 'time-to-convert',
            title: 'Time to Convert',
            summary: [
                $this->card('converted', 'Conversions', ReportFormatter::count($summary['count']), 'task_alt'),
                $this->card('average', 'Average Time', ReportFormatter::days($summary['average_days']), 'timer'),
                $this->card('median', 'Median Time', ReportFormatter::days($summary['median_days']), 'straighten',
                    hint: 'Less distorted by one slow customer than the average'),
                $this->card('slowest', 'Slowest', ReportFormatter::days($summary['slowest_days']), 'hourglass_bottom',
                    hint: 'Fastest: '.ReportFormatter::days($summary['fastest_days'])),
            ],
            rows: $rows,
            columns: [
                ['key' => 'quotation_number', 'label' => 'Quotation'],
                ['key' => 'invoice_number', 'label' => 'Invoice'],
                ['key' => 'days', 'label' => 'Time to Convert', 'align' => 'right', 'format' => 'days', 'emphasis' => 'strong'],
            ],
            meta: [
                'table_title' => 'Conversions in this period',
                'empty_title' => 'No quotations were converted in this period',
                'empty_message' => 'A quotation appears here once it has produced an invoice.',
            ],
        );
    }

    /**
     * Cancelled quotations — lost business, with who cancelled and when.
     */
    public function cancelled(ReportFilter $filter): ReportResult
    {
        $totals = $this->quotations->funnelTotals($filter);

        $rows = $this->quotations->cancelledQuotations($filter)->map(fn (array $row): array => [
            'quotation_number' => $row['quotation_number'],
            'quotation_date' => $row['quotation_date'],
            'customer' => $row['customer'],
            'cancelled_by' => ReportFormatter::actor($row['actor_name'], $row['actor_role'], $row['actor_status'], $row['actor_deleted_at']),
            'cancelled_at' => $row['cancelled_at'],
            'value' => $row['value'],
        ]);

        return new ReportResult(
            key: 'cancelled-quotations',
            title: 'Cancelled Quotations',
            summary: [
                $this->card('cancelled', 'Cancelled', ReportFormatter::count($totals['cancelled']), 'cancel'),
                $this->card('value', 'Quoted Value Lost', ReportFormatter::money($rows->sum('value')), 'money_off'),
                $this->card('rate', 'Cancellation Rate',
                    ReportFormatter::percent($totals['total'] > 0 ? $totals['cancelled'] / $totals['total'] * 100 : 0),
                    'percent'),
            ],
            rows: $rows,
            columns: [
                ['key' => 'quotation_number', 'label' => 'Quotation'],
                ['key' => 'quotation_date', 'label' => 'Raised'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'cancelled_by', 'label' => 'Cancelled By'],
                ['key' => 'cancelled_at', 'label' => 'Cancelled At', 'format' => 'datetime'],
                ['key' => 'value', 'label' => 'Quoted Value', 'align' => 'right', 'format' => 'money', 'emphasis' => 'strong'],
            ],
            totals: [
                'quotation_number' => 'Total',
                'value' => $rows->sum('value'),
            ],
            meta: [
                'table_title' => 'Cancelled quotations',
                'money' => true,
                'empty_title' => 'No quotations were cancelled in this period',
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
