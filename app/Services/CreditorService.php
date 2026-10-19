<?php

namespace App\Services;

use App\Models\Creditor;
use App\Models\Performa;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CreditorService
 *
 * Branch-scoped credit-party master data. Performas attach to a creditor
 * registered here — never to a sales customer. Phone uniqueness is per branch.
 */
class CreditorService
{
    public function __construct(
        private CodeSequenceService $codeSequence,
        private AuditLogService $auditLog,
    ) {}

    /**
     * Paginated list with search and performa counts.
     *
     * @param  array{search?: string, status?: string}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Creditor::query()
            ->withCount('performas')
            ->orderByDesc('created_at');

        $this->applySearch($query, $filters);

        if (($filters['status'] ?? '') === 'active') {
            $query->where('status', true);
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $query->where('status', false);
        }

        return $query->paginate(per_page())->withQueryString();
    }

    /**
     * Fast search for the performa creditor picker (AJAX).
     *
     * @return Collection<int, Creditor>
     */
    public function search(string $term, int $limit = 15): Collection
    {
        $query = Creditor::query()->active()->orderBy('name')->limit($limit);

        if (trim($term) !== '') {
            $this->applySearch($query, ['search' => $term]);
        }

        return $query->get(['id', 'uuid', 'creditor_code', 'name', 'phone', 'email', 'nie', 'address', 'city']);
    }

    /**
     * Every active creditor of the branch, for the POS to filter locally
     * the way it does with customers.
     *
     * @return Collection<int, Creditor>
     */
    public function catalog(int $limit = 5000): Collection
    {
        return Creditor::query()
            ->active()
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'uuid', 'creditor_code', 'name', 'phone', 'email', 'nie', 'address', 'city']);
    }

    /**
     * Register a new creditor on the actor's branch.
     *
     * Wrapped in a transaction because creation also writes an audit log
     * entry — both must succeed or neither should.
     *
     * @param  array<string, mixed>  $data  Validated data from StoreCreditorRequest
     */
    public function create(array $data, User $actor): Creditor
    {
        if ((int) $actor->branch_id < 1) {
            abort(403);
        }

        return DB::transaction(function () use ($data, $actor): Creditor {
            $creditor = Creditor::query()->create([
                'branch_id' => $actor->branch_id,
                'creditor_code' => $this->codeSequence->nextCreditorCode(),
                'name' => $data['name'],
                'phone' => $data['phone'],
                'alternate_phone' => $data['alternate_phone'] ?? null,
                'email' => $data['email'] ?? null,
                'nie' => filled($data['nie'] ?? null) ? strtoupper(trim($data['nie'])) : null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => true,
            ]);

            $this->auditLog->log('creditor.created', $creditor, null, $this->auditPayload($creditor), $actor);

            return $creditor;
        });
    }

    /**
     * Update creditor details. Code and branch never change.
     *
     * @param  array<string, mixed>  $data  Validated data from UpdateCreditorRequest
     */
    /**
     * Save what the cashier changed in a loaded creditor's fields on the POS
     * (name, email, NIE, address; the phone number stays). Same rules as
     * CustomerService::syncFromTicket.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncFromTicket(Creditor $creditor, array $data, User $actor): Creditor
    {
        if (! \Illuminate\Support\Facades\Gate::forUser($actor)->allows('update', $creditor)) {
            return $creditor;
        }

        $clean = static fn (mixed $v): ?string => filled(trim((string) $v)) ? trim((string) $v) : null;

        $changes = [];
        if (array_key_exists('walkin_name', $data) && $clean($data['walkin_name']) !== null) {
            $changes['name'] = $clean($data['walkin_name']);
        }
        foreach (['email' => 'walkin_email', 'address' => 'walkin_address'] as $column => $key) {
            if (array_key_exists($key, $data)) {
                $changes[$column] = $clean($data[$key]);
            }
        }
        if (array_key_exists('walkin_nie', $data)) {
            $nie = $clean($data['walkin_nie']);
            $changes['nie'] = $nie === null ? null : strtoupper($nie);
        }

        $changes = array_filter(
            $changes,
            fn ($value, $column): bool => $creditor->{$column} !== $value,
            ARRAY_FILTER_USE_BOTH
        );

        if ($changes === []) {
            return $creditor;
        }

        return DB::transaction(function () use ($creditor, $changes, $actor): Creditor {
            $old = $this->auditPayload($creditor);
            $creditor->update($changes);
            $this->auditLog->log('creditor.updated', $creditor, $old, $this->auditPayload($creditor), $actor);

            return $creditor->fresh();
        });
    }

    public function update(Creditor $creditor, array $data, User $actor): Creditor
    {
        if ((int) $creditor->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        return DB::transaction(function () use ($creditor, $data, $actor): Creditor {
            $old = $this->auditPayload($creditor);

            $creditor->update([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'alternate_phone' => $data['alternate_phone'] ?? null,
                'email' => $data['email'] ?? null,
                'nie' => filled($data['nie'] ?? null) ? strtoupper(trim($data['nie'])) : null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->auditLog->log('creditor.updated', $creditor, $old, $this->auditPayload($creditor), $actor);

            return $creditor->fresh();
        });
    }

    /**
     * Hide the creditor from the performa picker. Existing performas stay.
     */
    public function deactivate(Creditor $creditor, User $actor): Creditor
    {
        if ((int) $creditor->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        if (! $creditor->status) {
            throw ValidationException::withMessages([
                'creditor' => __('This creditor is already deactivated.'),
            ]);
        }

        return DB::transaction(function () use ($creditor, $actor): Creditor {
            $old = $this->auditPayload($creditor);
            $creditor->update(['status' => false]);
            $this->auditLog->log('creditor.deactivated', $creditor, $old, $this->auditPayload($creditor), $actor);

            return $creditor->fresh();
        });
    }

    /**
     * Put the creditor back on the performa picker.
     */
    public function activate(Creditor $creditor, User $actor): Creditor
    {
        if ((int) $creditor->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        if ($creditor->status) {
            throw ValidationException::withMessages([
                'creditor' => __('This creditor is already active.'),
            ]);
        }

        return DB::transaction(function () use ($creditor, $actor): Creditor {
            $old = $this->auditPayload($creditor);
            $creditor->update(['status' => true]);
            $this->auditLog->log('creditor.activated', $creditor, $old, $this->auditPayload($creditor), $actor);

            return $creditor->fresh();
        });
    }

    /**
     * Performa history for the creditor detail screen.
     *
     * @return Collection<int, Performa>
     */
    public function performaHistory(Creditor $creditor, int $limit = 50): Collection
    {
        return Performa::query()
            ->with(['creator', 'items'])
            ->where('creditor_id', $creditor->id)
            ->orderByDesc('performa_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Builder<Creditor>  $query
     * @param  array{search?: string}  $filters
     */
    private function applySearch(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $inner) use ($search): void {
            $inner->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('creditor_code', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(Creditor $creditor): array
    {
        return [
            'creditor_code' => $creditor->creditor_code,
            'name' => $creditor->name,
            'phone' => $creditor->phone,
            'status' => $creditor->status,
        ];
    }
}
