<?php

namespace App\Services;

use App\Enums\DeliveryNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\Creditor;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Performa;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TrashService
 *
 * Soft delete and restore for invoices, quotations, performas, delivery
 * notes, customers and creditors. Records are never purged: there is no
 * force-delete path anywhere in the application.
 *
 * Deleting a document that still holds stock first runs the document's own
 * void / cancel / return flow, so stock comes back through the same audited
 * code as a normal cancellation. Restoring only un-hides the row; it never
 * takes stock out again, so a restored document stays voided / cancelled.
 */
class TrashService
{
    /**
     * Trash tab slug => model class, permission and label.
     *
     * @var array<string, array{model: class-string<Model>, permission: string, label: string}>
     */
    public const TYPES = [
        'invoices' => ['model' => Invoice::class, 'permission' => 'invoices.delete', 'label' => 'Invoices'],
        'quotations' => ['model' => Quotation::class, 'permission' => 'quotations.delete', 'label' => 'Quotations'],
        'performas' => ['model' => Performa::class, 'permission' => 'performas.delete', 'label' => 'Performas'],
        'delivery-notes' => ['model' => DeliveryNote::class, 'permission' => 'delivery_notes.delete', 'label' => 'Delivery Notes'],
        'customers' => ['model' => Customer::class, 'permission' => 'customers.delete', 'label' => 'Clients'],
        'creditors' => ['model' => Creditor::class, 'permission' => 'creditors.delete', 'label' => 'Creditors'],
    ];

    public function __construct(
        private InvoiceService $invoiceService,
        private QuotationService $quotationService,
        private PerformaService $performaService,
        private DeliveryNoteService $deliveryNoteService,
        private AuditLogService $auditLog,
    ) {}

    /**
     * Tab slugs the user may see (they hold the matching delete permission).
     *
     * @return list<string>
     */
    public function typesFor(User $user): array
    {
        return array_values(array_filter(
            array_keys(self::TYPES),
            fn (string $type): bool => $user->hasPermission(self::TYPES[$type]['permission'])
        ));
    }

    /**
     * Move a record to the Trash, returning its stock first when it holds any.
     */
    public function delete(Model $record, User $actor): void
    {
        $this->assertSameBranch($record, $actor);

        DB::transaction(function () use ($record, $actor): void {
            $reason = __('Deleted (moved to Trash)');

            if ($record instanceof Invoice && $record->status === InvoiceStatus::COMPLETED) {
                $this->invoiceService->void($record, $reason, $actor);
            } elseif ($record instanceof Quotation && $record->status === QuotationStatus::DRAFT) {
                $this->quotationService->cancel($record, $actor);
            } elseif ($record instanceof Performa && $record->isOpen()) {
                $this->performaService->returnUnpaid($record, ['return_all' => true], $actor);
            } elseif ($record instanceof DeliveryNote
                && $record->status === DeliveryNoteStatus::ISSUED
                && ! $record->payments()->exists()) {
                $this->deliveryNoteService->cancel($record, $reason, $actor);
            }

            $record = $record->fresh();
            $record->delete();

            $this->auditLog->log($this->action($record, 'deleted'), $record, null, null, $actor);
        });
    }

    /**
     * Bring a record back from the Trash, in whatever status it was left.
     */
    public function restore(Model $record, User $actor): void
    {
        $this->assertSameBranch($record, $actor);

        if ($record instanceof Customer && Customer::query()->where('phone', $record->phone)->exists()) {
            throw ValidationException::withMessages([
                'restore' => __('Another customer now uses this phone number.'),
            ]);
        }

        if ($record instanceof Creditor
            && Creditor::query()->where('phone', $record->phone)->where('branch_id', $record->branch_id)->exists()) {
            throw ValidationException::withMessages([
                'restore' => __('Another creditor now uses this phone number.'),
            ]);
        }

        DB::transaction(function () use ($record, $actor): void {
            $record->restore();
            $this->auditLog->log($this->action($record, 'restored'), $record, null, null, $actor);
        });
    }

    /**
     * Trashed rows of one type, newest first.
     */
    public function trashed(string $type, int $perPage = 25): LengthAwarePaginator
    {
        $model = self::TYPES[$type]['model'];

        return $model::onlyTrashed()->orderByDesc('deleted_at')->paginate($perPage)->withQueryString();
    }

    /**
     * Trashed row counts per type, for the tab badges.
     *
     * @param  list<string>  $types
     * @return Collection<string, int>
     */
    public function counts(array $types): Collection
    {
        return collect($types)->mapWithKeys(fn (string $type): array => [
            $type => self::TYPES[$type]['model']::onlyTrashed()->count(),
        ]);
    }

    /**
     * Number / code, name and a status text for a trash row.
     *
     * @return array{code: string, name: string, status: string|null}
     */
    public function describe(Model $record): array
    {
        return match (true) {
            $record instanceof Invoice => ['code' => $record->invoice_number, 'name' => (string) $record->customer_name_snapshot, 'status' => $record->status->label()],
            $record instanceof Quotation => ['code' => $record->quotation_number, 'name' => $record->displayCustomerName(), 'status' => $record->status->label()],
            $record instanceof Performa => ['code' => $record->performa_number, 'name' => $record->displayCreditorName(), 'status' => $record->status->label()],
            $record instanceof DeliveryNote => ['code' => $record->delivery_note_number, 'name' => (string) $record->customer_name_snapshot, 'status' => $record->status->label()],
            $record instanceof Customer => ['code' => $record->customer_code, 'name' => $record->name, 'status' => null],
            default => ['code' => $record->creditor_code, 'name' => $record->name, 'status' => null],
        };
    }

    /**
     * Customers are global; every other record belongs to one branch.
     */
    private function assertSameBranch(Model $record, User $actor): void
    {
        if (! $record instanceof Customer && (int) $record->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }
    }

    private function action(Model $record, string $verb): string
    {
        return strtolower(class_basename($record)).'.'.$verb;
    }
}
