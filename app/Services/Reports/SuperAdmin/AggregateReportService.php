<?php

namespace App\Services\Reports\SuperAdmin;

use App\Models\Branch;
use App\Support\Reports\ReportFilter;
use Illuminate\Support\Collection;

/**
 * AggregateReportService
 *
 * Base for the SuperAdmin reporting services.
 *
 * These are deliberately a separate hierarchy from the Admin report services
 * rather than the same services behind a flag. Spec 7.4 forbids SuperAdmin from
 * reaching an individual invoice, quotation, customer, item or stone, and the
 * safest way to guarantee that is for the code path to have no way of producing
 * one: nothing in this namespace calls a repository method that returns record
 * rows, and every table a subclass emits is grouped — by branch, by status, by
 * category, by purity, by payment method.
 *
 * The shared shape is "one row per branch". In All Branches mode a report shows
 * the consolidated figures plus that branch-by-branch breakdown; choosing a
 * single branch narrows the same figures and unlocks nothing extra.
 */
abstract class AggregateReportService
{
    /**
     * Branches in scope for a filter, in display order.
     *
     * A null branchId means All Branches. A specific branch returns a
     * single-entry collection, which lets every subclass use one code path for
     * both modes.
     *
     * @return Collection<int, Branch>
     */
    protected function branchesInScope(ReportFilter $filter): Collection
    {
        $query = Branch::query()->orderBy('name');

        if ($filter->branchId !== null) {
            $query->whereKey($filter->branchId);
        }

        return $query->get(['id', 'name', 'code', 'status']);
    }

    /**
     * Run a per-branch metric callback and return one row per branch.
     *
     * The callback receives a filter already narrowed to that branch, so a
     * subclass never has to remember to scope — which is exactly the kind of
     * omission that leaks one branch's figures into another's row.
     *
     * @param  callable(ReportFilter, Branch): array<string, mixed>  $metrics
     * @return Collection<int, array<string, mixed>>
     */
    protected function perBranch(ReportFilter $filter, callable $metrics): Collection
    {
        return $this->branchesInScope($filter)
            ->map(fn (Branch $branch): array => array_merge(
                ['branch' => $branch->name],
                $metrics($filter->forBranch((int) $branch->id), $branch)
            ))
            ->values();
    }

    /**
     * Whether this run is the consolidated All Branches view.
     */
    protected function isConsolidated(ReportFilter $filter): bool
    {
        return $filter->branchId === null;
    }

    /**
     * A KPI card in the shape the shared shell expects.
     *
     * @param  ?array{direction: string, label: string}  $trend
     * @return array<string, mixed>
     */
    protected function card(string $key, string $label, string $value, ?string $icon = null, ?string $hint = null, ?array $trend = null): array
    {
        return compact('key', 'label', 'value', 'icon', 'hint', 'trend');
    }

    /**
     * Standard leading column for a branch breakdown table.
     *
     * @return array{key: string, label: string, format: string}
     */
    protected function branchColumn(): array
    {
        return ['key' => 'branch', 'label' => __('Branch'), 'format' => 'branch'];
    }

    /**
     * Meta every SuperAdmin report carries, stating the constraint plainly so
     * nobody wastes time looking for a drill-down that is refused by design.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function meta(array $extra = []): array
    {
        return array_merge([
            'aggregate_only' => true,
            'empty_title' => __('No activity in this period'),
        ], $extra);
    }
}
