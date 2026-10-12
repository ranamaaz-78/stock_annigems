<?php

namespace App\Repositories\Reports;

use App\Models\Scopes\BranchScope;
use App\Support\Reports\ReportFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * ReportRepository
 *
 * Shared base for every report repository. Holds the three rules that must be
 * identical across all of them:
 *
 * 1. Branch scoping is applied *explicitly* from the ReportFilter rather than
 *    by the BranchScope global scope. The global scope reads the authenticated
 *    user, which is right for operational screens but wrong here: the SuperAdmin
 *    aggregate reports need to target one specific branch, and relying on an
 *    implicit scope would silently return every branch for them. The filter's
 *    branchId is itself resolved from the authenticated user for Admin reports,
 *    so the tenant boundary is not weakened — it is just made visible.
 *
 * 2. Date ranges always compare against an inclusive whole-day window.
 *
 * 3. Aggregation happens in SQL. No report loads rows into PHP to sum them.
 */
abstract class ReportRepository
{
    /**
     * Start an Eloquent query with the implicit branch scope removed.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    protected function unscoped(string $model): Builder
    {
        return $model::query()->withoutGlobalScope(BranchScope::class);
    }

    /**
     * Constrain a query to the filter's branch.
     *
     * A null branchId means "all branches" and is only ever produced by the
     * SuperAdmin filter request; an Admin filter always carries a branch.
     *
     * @param  Builder<*>|QueryBuilder  $query
     * @param  string  $table Table owning the branch_id column
     */
    protected function scopeToBranch(Builder|QueryBuilder $query, ?int $branchId, string $table): Builder|QueryBuilder
    {
        if ($branchId !== null) {
            $query->where($table.'.branch_id', $branchId);
        }

        return $query;
    }

    /**
     * Constrain a query to the filter's inclusive range on a DATETIME column.
     *
     * @param  Builder<*>|QueryBuilder  $query
     * @param  string  $column Fully-qualified datetime column (created_at etc.)
     */
    protected function scopeToRange(Builder|QueryBuilder $query, ReportFilter $filter, string $column): Builder|QueryBuilder
    {
        return $query->whereBetween($column, [
            $filter->from->toDateTimeString(),
            $filter->to->toDateTimeString(),
        ]);
    }

    /**
     * Constrain a query to the filter's inclusive range on a DATE column.
     *
     * Separate from scopeToRange for a portability reason worth spelling out:
     * SQLite compares dates as plain strings, so a stored '2026-09-01' sorts
     * *before* the bound '2026-09-01 00:00:00' and the first day of the range
     * would be silently dropped from every report. Date columns therefore get
     * date-only bounds. This also keeps the (branch_id, status, invoice_date)
     * index usable, which a DATE() wrapper would defeat.
     *
     * @param  Builder<*>|QueryBuilder  $query
     * @param  string  $column Fully-qualified date column (invoice_date etc.)
     */
    protected function scopeToDateRange(Builder|QueryBuilder $query, ReportFilter $filter, string $column): Builder|QueryBuilder
    {
        return $query->whereBetween($column, [
            $filter->from->toDateString(),
            $filter->to->toDateString(),
        ]);
    }

    /**
     * Cast a possibly-null SQL aggregate to a float.
     *
     * SUM() over zero rows returns NULL, which would render as an em dash
     * rather than the zero a fresh branch should see.
     */
    protected function toFloat(mixed $value): float
    {
        return (float) ($value ?? 0);
    }

    /**
     * Cast a possibly-null SQL aggregate to an int.
     */
    protected function toInt(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    /**
     * Flatten a value that may be an enum into its stored scalar.
     *
     * Aggregates run through Eloquent inherit the model's casts, so a grouped
     * `status` or `payment_method` column arrives as a backed enum rather than
     * the string the query grouped on. Casting that straight to string is a
     * fatal error, so every grouped enum column goes through here.
     */
    protected function scalar(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) ($value ?? '');
    }
}
