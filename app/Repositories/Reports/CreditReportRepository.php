<?php

namespace App\Repositories\Reports;

use App\Enums\DeliveryNoteStatus;
use App\Enums\PerformaStatus;
use App\Models\DeliveryNote;
use App\Models\Performa;
use App\Support\Reports\ReportFilter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CreditReportRepository
 *
 * Goods that have left the shelf but are not sold yet: performas (issued to a
 * creditor, paid for or sent back later) and delivery notes (handed to a client,
 * invoiced later).
 *
 * Performa money is the value fixed on each line when it was issued
 * (performa_items.unit_price, per piece or per gram), so these figures never
 * move with today's gold rate. A line counts in grams when it carries
 * weight_issued and in pieces otherwise.
 */
class CreditReportRepository extends ReportRepository
{
    /**
     * Value of a line quantity at its issue price, as SQL: grams on a weighed
     * line, pieces otherwise.
     */
    private function valueSql(string $pieces, string $grams): string
    {
        return "performa_items.unit_price * CASE WHEN performa_items.weight_issued > 0 THEN performa_items.{$grams} ELSE performa_items.{$pieces} END";
    }

    /**
     * Open and partly paid performas, summed per creditor.
     *
     * Live: it describes what is out right now, so no date range applies.
     *
     * @return Collection<int, array{creditor: string, creditor_code: string, performas: int, issued: float, settled: float, returned: float, outstanding: float, oldest_days: int}>
     */
    public function outstandingByCreditor(?int $branchId): Collection
    {
        $issued = $this->valueSql('quantity_issued', 'weight_issued');
        $settled = $this->valueSql('quantity_settled', 'weight_settled');
        $returned = $this->valueSql('quantity_returned', 'weight_returned');

        return $this->summarise($branchId, $issued, $settled, $returned);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function summarise(?int $branchId, string $issued, string $settled, string $returned): Collection
    {
        $query = $this->unscoped(Performa::class)
            ->join('performa_items', 'performa_items.performa_id', '=', 'performas.id')
            ->leftJoin('creditors', 'creditors.id', '=', 'performas.creditor_id')
            ->whereIn('performas.status', [PerformaStatus::OPEN->value, PerformaStatus::PARTIAL->value]);

        $this->scopeToBranch($query, $branchId, 'performas');

        return $query
            ->groupBy('performas.creditor_id', 'creditors.name', 'creditors.creditor_code')
            ->selectRaw("COALESCE(creditors.name, 'Unknown creditor') as creditor_name")
            ->selectRaw("COALESCE(creditors.creditor_code, '—') as creditor_code")
            ->selectRaw('COUNT(DISTINCT performas.id) as performas')
            ->selectRaw("COALESCE(SUM({$issued}), 0) as issued_value")
            ->selectRaw("COALESCE(SUM({$settled}), 0) as settled_value")
            ->selectRaw("COALESCE(SUM({$returned}), 0) as returned_value")
            ->selectRaw('MIN(performas.performa_date) as oldest')
            ->get()
            ->map(function ($row): array {
                $issuedValue = round($this->toFloat($row->issued_value), 2);
                $settledValue = round($this->toFloat($row->settled_value), 2);
                $returnedValue = round($this->toFloat($row->returned_value), 2);

                return [
                    'creditor' => (string) $row->creditor_name,
                    'creditor_code' => (string) $row->creditor_code,
                    'performas' => $this->toInt($row->performas),
                    'issued' => $issuedValue,
                    'settled' => $settledValue,
                    'returned' => $returnedValue,
                    'outstanding' => round(max(0.0, $issuedValue - $settledValue - $returnedValue), 2),
                    'oldest_days' => (int) Carbon::parse($row->oldest)->diffInDays(now()),
                ];
            })
            ->sortByDesc('outstanding')
            ->values();
    }

    /**
     * Open and partly paid performas, oldest first, with what is still out.
     *
     * @return Collection<int, array{uuid: string, performa_number: string, performa_date: string, creditor: string, status: string, issued: float, outstanding: float, age_days: int}>
     */
    public function openPerformas(?int $branchId, int $limit): Collection
    {
        $issued = $this->valueSql('quantity_issued', 'weight_issued');
        $settled = $this->valueSql('quantity_settled', 'weight_settled');
        $returned = $this->valueSql('quantity_returned', 'weight_returned');

        $query = $this->unscoped(Performa::class)
            ->join('performa_items', 'performa_items.performa_id', '=', 'performas.id')
            ->leftJoin('creditors', 'creditors.id', '=', 'performas.creditor_id')
            ->whereIn('performas.status', [PerformaStatus::OPEN->value, PerformaStatus::PARTIAL->value]);

        $this->scopeToBranch($query, $branchId, 'performas');

        return $query
            ->groupBy('performas.id', 'performas.uuid', 'performas.performa_number', 'performas.performa_date', 'performas.status', 'creditors.name')
            ->orderBy('performas.performa_date')
            ->orderBy('performas.id')
            ->limit($limit)
            ->selectRaw('performas.uuid, performas.performa_number, performas.performa_date, performas.status')
            ->selectRaw("COALESCE(creditors.name, 'Unknown creditor') as creditor_name")
            ->selectRaw("COALESCE(SUM({$issued}), 0) as issued_value")
            ->selectRaw("COALESCE(SUM({$settled}), 0) as settled_value")
            ->selectRaw("COALESCE(SUM({$returned}), 0) as returned_value")
            ->get()
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'performa_number' => (string) $row->performa_number,
                'performa_date' => (string) $row->performa_date,
                'creditor' => (string) $row->creditor_name,
                'status' => $this->scalar($row->status),
                'issued' => round($this->toFloat($row->issued_value), 2),
                'outstanding' => round(max(0.0, $this->toFloat($row->issued_value) - $this->toFloat($row->settled_value) - $this->toFloat($row->returned_value)), 2),
                'age_days' => (int) Carbon::parse($row->performa_date)->diffInDays(now()),
            ]);
    }

    /**
     * Money actually collected by settling performas in the period: the
     * completed invoices those settlements produced.
     *
     * @return array{count: int, total: float}
     */
    public function settledInPeriod(ReportFilter $filter): array
    {
        $row = \App\Models\Invoice::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->completed()
            ->whereNotNull('invoices.performa_id')
            ->when($filter->branchId !== null, fn ($q) => $q->where('invoices.branch_id', $filter->branchId))
            ->whereBetween('invoices.invoice_date', [$filter->from->toDateString(), $filter->to->toDateString()])
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(grand_total), 0) as total')
            ->first();

        return ['count' => $this->toInt($row?->invoices), 'total' => round($this->toFloat($row?->total), 2)];
    }

    /**
     * Delivery notes dated in the period, newest first.
     *
     * @return Collection<int, array{uuid: string, delivery_note_number: string, delivery_date: string, customer: string, status: string, grand_total: float, invoice_number: ?string, age_days: int}>
     */
    public function deliveryNotes(ReportFilter $filter, int $limit): Collection
    {
        $query = $this->unscoped(DeliveryNote::class)
            ->leftJoin('invoices', 'invoices.id', '=', 'delivery_notes.invoice_id');

        $this->scopeToBranch($query, $filter->branchId, 'delivery_notes');
        $this->scopeToDateRange($query, $filter, 'delivery_notes.delivery_date');

        return $query
            ->orderByDesc('delivery_notes.delivery_date')
            ->orderByDesc('delivery_notes.id')
            ->limit($limit)
            ->get([
                'delivery_notes.uuid',
                'delivery_notes.delivery_note_number',
                'delivery_notes.delivery_date',
                'delivery_notes.customer_name_snapshot',
                'delivery_notes.status',
                'delivery_notes.grand_total',
                'invoices.invoice_number',
            ])
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'delivery_note_number' => (string) $row->delivery_note_number,
                'delivery_date' => (string) $row->delivery_date,
                'customer' => (string) $row->customer_name_snapshot,
                'status' => $this->scalar($row->status),
                'grand_total' => $this->toFloat($row->grand_total),
                'invoice_number' => $row->invoice_number,
                'age_days' => (int) Carbon::parse($row->delivery_date)->diffInDays(now()),
            ]);
    }

    /**
     * Count and value of delivery notes in the period, per status.
     *
     * @return Collection<int, array{status: string, notes: int, value: float}>
     */
    public function deliveryNotesByStatus(ReportFilter $filter): Collection
    {
        $query = $this->unscoped(DeliveryNote::class);

        $this->scopeToBranch($query, $filter->branchId, 'delivery_notes');
        $this->scopeToDateRange($query, $filter, 'delivery_notes.delivery_date');

        $rows = $query
            ->groupBy('delivery_notes.status')
            ->selectRaw('delivery_notes.status as status, COUNT(*) as notes, COALESCE(SUM(grand_total), 0) as value')
            ->get()
            ->keyBy(fn ($row): string => $this->scalar($row->status));

        return collect(DeliveryNoteStatus::cases())->map(fn (DeliveryNoteStatus $status): array => [
            'status' => $status->value,
            'notes' => $this->toInt($rows[$status->value]->notes ?? 0),
            'value' => round($this->toFloat($rows[$status->value]->value ?? 0), 2),
        ]);
    }

    /**
     * Notes that left the shop and have not been invoiced or cancelled, oldest
     * first. Live: the whole backlog, not just the period.
     *
     * @return Collection<int, array{uuid: string, delivery_note_number: string, delivery_date: string, customer: string, grand_total: float, age_days: int}>
     */
    public function uninvoicedNotes(?int $branchId, int $limit): Collection
    {
        $query = $this->unscoped(DeliveryNote::class)
            ->where('delivery_notes.status', DeliveryNoteStatus::ISSUED->value);

        $this->scopeToBranch($query, $branchId, 'delivery_notes');

        return $query
            ->orderBy('delivery_notes.delivery_date')
            ->orderBy('delivery_notes.id')
            ->limit($limit)
            ->get(['uuid', 'delivery_note_number', 'delivery_date', 'customer_name_snapshot', 'grand_total'])
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'delivery_note_number' => (string) $row->delivery_note_number,
                'delivery_date' => (string) $row->delivery_date,
                'customer' => (string) $row->customer_name_snapshot,
                'grand_total' => $this->toFloat($row->grand_total),
                'age_days' => (int) Carbon::parse($row->delivery_date)->diffInDays(now()),
            ]);
    }
}
