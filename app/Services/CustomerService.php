<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CustomerService
 *
 * Global customer master data. Deliberately not branch-scoped — any branch
 * may create, search, and edit any customer. Phone uniqueness is system-wide.
 */
class CustomerService
{
    public function __construct(
        private CodeSequenceService $codeSequence,
        private AuditLogService $auditLog,
    ) {}

    /**
     * Paginated list with search and counts.
     *
     * @param  array{search?: string, status?: string}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Customer::query()
            ->withCount(['quotations', 'invoices'])
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
     * Fast search for quotation customer picker (AJAX).
     *
     * @return Collection<int, Customer>
     */
    public function search(string $term, int $limit = 15): Collection
    {
        $query = Customer::query()->active()->orderBy('name')->limit($limit);

        if (trim($term) !== '') {
            $this->applySearch($query, ['search' => $term]);
        }

        return $query->get(['id', 'uuid', 'customer_code', 'name', 'phone', 'email', 'city']);
    }

    /**
     * Every active customer for the POS phone field.
     *
     * POS loads this once and filters in the browser, the same way the item
     * catalog works, so typing a phone number does not send a request.
     *
     * @return Collection<int, Customer>
     */
    public function catalog(int $limit = 2000): Collection
    {
        return Customer::query()
            ->active()
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'uuid', 'customer_code', 'name', 'phone', 'email', 'nie', 'address', 'city']);
    }

    /**
     * Exact phone match after trim. Used by POS before a document is saved
     * so an existing name/email can fill the strip without creating a row.
     */
    public function findByPhone(string $phone): ?Customer
    {
        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        return Customer::query()->where('phone', $phone)->first();
    }

    /**
     * Reuse the customer with this phone, or register a new one.
     *
     * Walk-in quotation capture must not duplicate the unique phone index:
     * the same number always maps to the same customer row. The existing
     * name is left unchanged when a match is found.
     *
     * Unique-constraint races (two cashiers saving the same number together)
     * are treated as a hit on the row the other request inserted.
     *
     * @param  string  $name  Used only when inserting a new customer
     * @param  string  $phone  Matched exactly after trim (system-wide unique)
     * @param  string|null  $email  Stored only on insert; existing rows keep theirs
     */
    public function findOrCreateByPhone(string $name, string $phone, User $actor, ?string $email = null, ?string $nie = null, ?string $address = null): Customer
    {
        $phone = trim($phone);
        $name = trim($name);
        $email = $email !== null ? trim($email) : null;
        $email = $email === '' ? null : $email;

        // The phone index also covers customers in the Trash; reuse one of
        // those by restoring it instead of failing on the unique key.
        $existing = Customer::withTrashed()->where('phone', $phone)->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        try {
            return $this->create([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'nie' => $nie,
                'address' => $address,
            ], $actor);
        } catch (UniqueConstraintViolationException $exception) {
            $raced = Customer::query()->where('phone', $phone)->first();
            if ($raced) {
                return $raced;
            }

            throw $exception;
        }
    }

    /**
     * Save what the cashier changed in a loaded customer's fields on the POS.
     *
     * Name, email, NIE, address and phone can change here (a phone another
     * customer already uses is refused). Users who may not edit
     * customers have the ticket go through unchanged. Nothing is written when
     * nothing differs, so ordinary sales leave no audit noise.
     *
     * @param  array<string, mixed>  $data  The ticket: walkin_name / _email / _nie / _address
     */
    public function syncFromTicket(Customer $customer, array $data, User $actor): Customer
    {
        if (! \Illuminate\Support\Facades\Gate::forUser($actor)->allows('update', $customer)) {
            return $customer;
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

        // The phone can be corrected too, as long as no other customer (trashed
        // ones included — the unique index covers them) already holds it.
        $phone = $clean($data['walkin_phone'] ?? null);
        if ($phone !== null && $phone !== $customer->phone) {
            $taken = Customer::withTrashed()->where('phone', $phone)->where('id', '!=', $customer->id)->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'walkin_phone' => __('Another customer already uses the phone :phone.', ['phone' => $phone]),
                ]);
            }
            $changes['phone'] = $phone;
        }

        $changes = array_filter(
            $changes,
            fn ($value, $column): bool => $customer->{$column} !== $value,
            ARRAY_FILTER_USE_BOTH
        );

        if ($changes === []) {
            return $customer;
        }

        return DB::transaction(function () use ($customer, $changes, $actor): Customer {
            $old = $this->auditPayload($customer);
            $customer->update($changes);
            $this->auditLog->log('customer.updated', $customer, $old, $this->auditPayload($customer), $actor);

            return $customer->fresh();
        });
    }

    /**
     * Create a new global customer.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Customer
    {
        return DB::transaction(function () use ($data, $actor): Customer {
            $customer = Customer::query()->create([
                'customer_code' => $this->codeSequence->nextCustomerCode(),
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

            $this->auditLog->log('customer.created', $customer, null, $this->auditPayload($customer), $actor);

            return $customer;
        });
    }

    /**
     * Update customer details.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $data, $actor): Customer {
            $old = $this->auditPayload($customer);

            $customer->update([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'alternate_phone' => $data['alternate_phone'] ?? null,
                'email' => $data['email'] ?? null,
                'nie' => filled($data['nie'] ?? null) ? strtoupper(trim($data['nie'])) : null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->auditLog->log('customer.updated', $customer, $old, $this->auditPayload($customer), $actor);

            return $customer->fresh();
        });
    }

    /**
     * Deactivate when no quotations or invoices exist.
     */
    public function deactivate(Customer $customer, User $actor): Customer
    {
        if (! $this->canDeactivate($customer)) {
            throw ValidationException::withMessages([
                'customer' => __('This customer has quotations or invoices and cannot be deleted. Deactivate only when they have no history, or leave them active.'),
            ]);
        }

        return DB::transaction(function () use ($customer, $actor): Customer {
            $old = $this->auditPayload($customer);
            $customer->update(['status' => false]);
            $this->auditLog->log('customer.deactivated', $customer, $old, $this->auditPayload($customer), $actor);

            return $customer->fresh();
        });
    }

    /**
     * Whether the customer can be deactivated (always allowed) vs has history.
     * Spec: cannot delete with history; deactivate is always allowed.
     */
    public function hasHistory(Customer $customer): bool
    {
        return Quotation::query()->where('customer_id', $customer->id)->exists()
            || Invoice::query()->where('customer_id', $customer->id)->exists();
    }

    /**
     * @deprecated Use hasHistory — deactivation is always permitted per spec.
     */
    public function canDeactivate(Customer $customer): bool
    {
        return true;
    }

    /**
     * Quotation history across all branches for customer detail.
     */
    public function quotationHistory(Customer $customer, int $limit = 50): Collection
    {
        return Quotation::query()
            ->withoutGlobalScopes()
            ->with(['branch:id,name', 'creator'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('quotation_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Invoice history across all branches for customer detail.
     */
    public function invoiceHistory(Customer $customer, int $limit = 50): Collection
    {
        return Invoice::query()
            ->withoutGlobalScopes()
            ->with(['branch:id,name', 'creator'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('invoice_date')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Builder<Customer>  $query
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
                ->orWhere('customer_code', 'like', "%{$search}%");
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(Customer $customer): array
    {
        return [
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'status' => $customer->status,
        ];
    }
}
