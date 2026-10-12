<?php

namespace App\Repositories\Reports;

use App\Models\AuditLog;
use App\Models\GoldRate;
use App\Support\Reports\ReportFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * AuditReportRepository
 *
 * Reads the trails other modules already write: audit_logs, and the gold rate
 * history table.
 *
 * Every actor column selected here is paired with the role snapshot stored on
 * the record itself (`user_role`, `created_by_role`), never with the actor's
 * current role. Someone who created an invoice as a Cashier and was later
 * promoted must still appear in the trail as the Cashier who did it — that is
 * the whole value of an audit trail.
 */
class AuditReportRepository extends ReportRepository
{
    /**
     * Individual audit entries in the period.
     *
     * @return Collection<int, array{
     *     created_at: string, action: string, action_label: string,
     *     model_type: string, actor_name: ?string, actor_role: ?string,
     *     actor_status: ?string, actor_deleted_at: ?string, ip_address: ?string
     * }>
     */
    public function activity(ReportFilter $filter, int $limit): Collection
    {
        return $this->logs($filter)
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->orderByDesc('audit_logs.created_at')
            ->orderByDesc('audit_logs.id')
            ->limit($limit)
            ->get([
                'audit_logs.created_at',
                'audit_logs.action',
                'audit_logs.model_type',
                'audit_logs.ip_address',
                'audit_logs.user_role as actor_role',
                'users.name as actor_name',
                'users.status as actor_status',
                'users.deleted_at as actor_deleted_at',
            ])
            ->map(fn ($row): array => [
                'created_at' => (string) $row->created_at,
                'action' => (string) $row->action,
                // The model's own label map keeps action wording in one place.
                'action_label' => (new AuditLog(['action' => $row->action]))->actionLabel(),
                'model_type' => class_basename((string) $row->model_type),
                'actor_name' => $row->actor_name,
                'actor_role' => $row->actor_role,
                'actor_status' => $row->actor_status,
                'actor_deleted_at' => $row->actor_deleted_at,
                'ip_address' => $row->ip_address,
            ]);
    }

    /**
     * Action counts per user, for the "who is doing what" summary.
     *
     * @return Collection<int, array{
     *     actor_name: ?string, actor_role: ?string, actor_status: ?string,
     *     actor_deleted_at: ?string, actions: int, creates: int, updates: int, deletes: int, last_action_at: ?string
     * }>
     */
    public function activityByUser(ReportFilter $filter): Collection
    {
        return $this->logs($filter)
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->selectRaw('users.name as actor_name, audit_logs.user_role as actor_role')
            ->selectRaw('users.status as actor_status, users.deleted_at as actor_deleted_at')
            ->selectRaw('COUNT(*) as actions')
            // Actions are namespaced "model.verb", so the verb suffix is what
            // classifies them. LIKE keeps this portable across both drivers.
            ->selectRaw("SUM(CASE WHEN audit_logs.action LIKE '%created' THEN 1 ELSE 0 END) as creates")
            ->selectRaw("SUM(CASE WHEN audit_logs.action LIKE '%updated' THEN 1 ELSE 0 END) as updates")
            ->selectRaw("SUM(CASE WHEN audit_logs.action LIKE '%deleted' OR audit_logs.action LIKE '%deactivated' OR audit_logs.action LIKE '%voided' OR audit_logs.action LIKE '%cancelled' THEN 1 ELSE 0 END) as deletes")
            ->selectRaw('MAX(audit_logs.created_at) as last_action_at')
            ->groupBy('users.name', 'audit_logs.user_role', 'users.status', 'users.deleted_at')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row): array => [
                'actor_name' => $row->actor_name,
                'actor_role' => $row->actor_role,
                'actor_status' => $row->actor_status,
                'actor_deleted_at' => $row->actor_deleted_at,
                'actions' => $this->toInt($row->actions),
                'creates' => $this->toInt($row->creates),
                'updates' => $this->toInt($row->updates),
                'deletes' => $this->toInt($row->deletes),
                'last_action_at' => $row->last_action_at,
            ]);
    }

    /**
     * Audit entries for sales documents only — invoices and quotations.
     *
     * This is the trail spec 5.10 asks for: created, converted, cancelled and
     * voided, with the actor and the role held at the time.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function documentTrail(ReportFilter $filter, int $limit): Collection
    {
        $query = $this->logs($filter)
            ->whereIn('audit_logs.model_type', [
                \App\Models\Invoice::class,
                \App\Models\Quotation::class,
            ]);

        return $query
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->orderByDesc('audit_logs.created_at')
            ->orderByDesc('audit_logs.id')
            ->limit($limit)
            ->get([
                'audit_logs.created_at',
                'audit_logs.action',
                'audit_logs.model_type',
                'audit_logs.new_values',
                'audit_logs.user_role as actor_role',
                'users.name as actor_name',
                'users.status as actor_status',
                'users.deleted_at as actor_deleted_at',
            ])
            ->map(function ($row): array {
                $values = is_string($row->new_values)
                    ? (json_decode($row->new_values, true) ?: [])
                    : (array) $row->new_values;

                return [
                    'created_at' => (string) $row->created_at,
                    'document' => class_basename((string) $row->model_type),
                    // The audit payload carries the document number where the
                    // writing service recorded one; otherwise the row still
                    // stands on its own as an action plus an actor.
                    'reference' => $values['invoice_number'] ?? $values['quotation_number'] ?? null,
                    'action_label' => (new AuditLog(['action' => $row->action]))->actionLabel(),
                    'actor_name' => $row->actor_name,
                    'actor_role' => $row->actor_role,
                    'actor_status' => $row->actor_status,
                    'actor_deleted_at' => $row->actor_deleted_at,
                ];
            });
    }

    /**
     * Gold rate changes in the period.
     *
     * Rates are global (SuperAdmin managed, no branch_id), so this report is
     * identical for every branch — which is correct: everyone priced against
     * the same rate on the same day.
     *
     * @return Collection<int, array{
     *     effective_date: string, metal: string, purity: string, rate_per_gram: float,
     *     change: ?float, actor_name: ?string, actor_role: ?string,
     *     actor_status: ?string, actor_deleted_at: ?string, created_at: string
     * }>
     */
    public function goldRateHistory(ReportFilter $filter, int $limit): Collection
    {
        $query = GoldRate::query()
            ->join('purities', 'purities.id', '=', 'gold_rates.purity_id')
            ->join('metal_types', 'metal_types.id', '=', 'purities.metal_type_id')
            ->leftJoin('users', 'users.id', '=', 'gold_rates.created_by')
            ->whereBetween('gold_rates.effective_date', [
                $filter->from->toDateString(),
                $filter->to->toDateString(),
            ]);

        if ($filter->hasOption('purity_id')) {
            $query->where('gold_rates.purity_id', $filter->option('purity_id'));
        }

        $rows = $query
            ->orderByDesc('gold_rates.effective_date')
            ->orderByDesc('gold_rates.id')
            ->limit($limit)
            ->get([
                'gold_rates.purity_id',
                'gold_rates.effective_date',
                'gold_rates.rate_per_gram',
                'gold_rates.created_at',
                'gold_rates.created_by_role as actor_role',
                'purities.name as purity_name',
                'metal_types.name as metal_name',
                'users.name as actor_name',
                'users.status as actor_status',
                'users.deleted_at as actor_deleted_at',
            ]);

        // Movement against the previous rate for the same purity. Computed in
        // PHP over the already-ordered result rather than with a window
        // function, which SQLite in the test suite does not support uniformly.
        $previousByPurity = [];
        $ordered = $rows->sortBy([['effective_date', 'asc'], ['created_at', 'asc']]);

        foreach ($ordered as $row) {
            $purityId = (int) $row->purity_id;
            $rate = (float) $row->rate_per_gram;

            $row->rate_change = array_key_exists($purityId, $previousByPurity)
                ? $rate - $previousByPurity[$purityId]
                : null;

            $previousByPurity[$purityId] = $rate;
        }

        return $rows->map(fn ($row): array => [
            'effective_date' => \Illuminate\Support\Carbon::parse($row->effective_date)->toDateString(),
            'metal' => (string) $row->metal_name,
            'purity' => (string) $row->purity_name,
            'rate_per_gram' => (float) $row->rate_per_gram,
            'change' => $row->rate_change,
            'actor_name' => $row->actor_name,
            'actor_role' => $row->actor_role,
            'actor_status' => $row->actor_status,
            'actor_deleted_at' => $row->actor_deleted_at,
            'created_at' => (string) $row->created_at,
        ])->values();
    }

    /**
     * Base audit-log query for the filter's branch, range and optional actor.
     *
     * Branch scoping matters here: audit_logs carries branch_id, and a branch
     * Admin must only see their own branch's activity. Rows with a null
     * branch_id are SuperAdmin-level actions and are correctly excluded from a
     * branch report by the scope.
     */
    private function logs(ReportFilter $filter): Builder
    {
        $query = AuditLog::query();

        $this->scopeToBranch($query, $filter->branchId, 'audit_logs');
        $this->scopeToRange($query, $filter, 'audit_logs.created_at');

        if ($filter->hasOption('staff_id')) {
            $query->where('audit_logs.user_id', $filter->option('staff_id'));
        }

        return $query;
    }
}
