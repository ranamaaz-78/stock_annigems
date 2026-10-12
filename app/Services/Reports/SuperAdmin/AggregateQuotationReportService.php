<?php

namespace App\Services\Reports\SuperAdmin;

use App\Repositories\Reports\QuotationReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;

/**
 * AggregateQuotationReportService
 *
 * SuperAdmin quotation reporting: conversion performance per branch.
 *
 * The Admin surface additionally lists pending drafts and individual cancelled
 * quotations. Neither exists here — those are record listings, so the methods
 * that would produce them are absent from this class entirely.
 */
class AggregateQuotationReportService extends AggregateReportService
{
    public function __construct(
        private QuotationReportRepository $quotations,
    ) {}

    /**
     * Conversion funnel, consolidated and per branch.
     */
    public function funnel(ReportFilter $filter): ReportResult
    {
        $totals = $this->quotations->funnelTotals($filter);
        $previous = $this->quotations->funnelTotals($filter->previousPeriod());

        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $branchTotals = $this->quotations->funnelTotals($scoped);

            return [
                'total' => $branchTotals['total'],
                'converted' => $branchTotals['converted'],
                'cancelled' => $branchTotals['cancelled'],
                'conversion_rate' => $branchTotals['conversion_rate'],
                'converted_value' => $branchTotals['converted_value'],
            ];
        });

        return new ReportResult(
            key: 'quotation-funnel',
            title: 'Quotation Funnel',
            summary: [
                $this->card('total', 'Quotations Raised', ReportFormatter::count($totals['total']), 'request_quote',
                    trend: ReportFormatter::trend($totals['total'], $previous['total'])),
                $this->card('converted', 'Converted', ReportFormatter::count($totals['converted']), 'task_alt',
                    hint: ReportFormatter::money($totals['converted_value']).' billed'),
                $this->card('conversion_rate', 'Conversion Rate', ReportFormatter::percent($totals['conversion_rate']), 'percent',
                    trend: ReportFormatter::trend($totals['conversion_rate'], $previous['conversion_rate'])),
                $this->card('open', 'Still Open', ReportFormatter::count($totals['draft']), 'pending'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'total', 'label' => 'Raised', 'align' => 'right', 'format' => 'count'],
                ['key' => 'converted', 'label' => 'Converted', 'align' => 'right', 'format' => 'count'],
                ['key' => 'cancelled', 'label' => 'Cancelled', 'align' => 'right', 'format' => 'count'],
                ['key' => 'conversion_rate', 'label' => 'Conversion Rate', 'align' => 'right', 'format' => 'percent', 'emphasis' => 'strong'],
                ['key' => 'converted_value', 'label' => 'Converted Value', 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'branch' => 'All Branches',
                'total' => $rows->sum('total'),
                'converted' => $rows->sum('converted'),
                'cancelled' => $rows->sum('cancelled'),
                'converted_value' => $rows->sum('converted_value'),
            ],
            chart: ReportResult::chart('Conversion rate by branch', 'percent', $rows->map(fn (array $row): array => [
                'label' => $row['branch'],
                'value' => $row['conversion_rate'],
                'display' => ReportFormatter::percent($row['conversion_rate']),
            ])->all()),
            meta: $this->meta([
                'table_title' => 'Funnel by branch',
                'money' => true,
                'empty_title' => 'No quotations raised in this period',
            ]),
        );
    }

    /**
     * Cancellation volume per branch — counts and lost quoted value only.
     */
    public function cancelled(ReportFilter $filter): ReportResult
    {
        $rows = $this->perBranch($filter, function (ReportFilter $scoped): array {
            $branchTotals = $this->quotations->funnelTotals($scoped);
            $lost = $this->quotations->cancelledQuotations($scoped)->sum('value');

            return [
                'total' => $branchTotals['total'],
                'cancelled' => $branchTotals['cancelled'],
                'cancellation_rate' => $branchTotals['total'] > 0
                    ? $branchTotals['cancelled'] / $branchTotals['total'] * 100
                    : 0.0,
                'value_lost' => $lost,
            ];
        });

        return new ReportResult(
            key: 'cancelled-quotations',
            title: 'Cancelled Quotations',
            summary: [
                $this->card('cancelled', 'Cancelled', ReportFormatter::count($rows->sum('cancelled')), 'cancel'),
                $this->card('value', 'Quoted Value Lost', ReportFormatter::money($rows->sum('value_lost')), 'money_off'),
                $this->card('rate', 'Cancellation Rate',
                    ReportFormatter::percent($rows->sum('total') > 0 ? $rows->sum('cancelled') / $rows->sum('total') * 100 : 0),
                    'percent'),
            ],
            rows: $rows,
            columns: [
                $this->branchColumn(),
                ['key' => 'total', 'label' => 'Raised', 'align' => 'right', 'format' => 'count'],
                ['key' => 'cancelled', 'label' => 'Cancelled', 'align' => 'right', 'format' => 'count', 'emphasis' => 'strong'],
                ['key' => 'cancellation_rate', 'label' => 'Rate', 'align' => 'right', 'format' => 'percent'],
                ['key' => 'value_lost', 'label' => 'Quoted Value Lost', 'align' => 'right', 'format' => 'money'],
            ],
            totals: [
                'branch' => 'All Branches',
                'total' => $rows->sum('total'),
                'cancelled' => $rows->sum('cancelled'),
                'value_lost' => $rows->sum('value_lost'),
            ],
            meta: $this->meta(['table_title' => 'Cancellations by branch', 'money' => true]),
        );
    }
}
