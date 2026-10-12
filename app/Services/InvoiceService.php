<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\StoneMovementType;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\Stone;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InvoiceService
 *
 * Invoice listing, detail loading, and voiding. Invoices are immutable after
 * creation — corrections are made by voiding and re-issuing.
 */
class InvoiceService
{
    public function __construct(
        private JewelleryItemService $jewelleryItemService,
        private StoneStockService $stoneStockService,
        private AuditLogService $auditLog,
        private PerformaSettlementService $performaSettlementService,
        private DeliveryNoteService $deliveryNoteService,
    ) {}

    /**
     * Branch-scoped paginated invoice list.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Invoice::query()
            ->with(['customer', 'creator'])
            ->withCount('items')
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('invoice_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('invoice_date', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($term): void {
                $q->where('invoice_number', 'like', "%{$term}%")
                    ->orWhere('customer_name_snapshot', 'like', "%{$term}%");
            });
        }

        return $query->paginate(per_page())->withQueryString();
    }

    /**
     * Load invoice with all relations for show/print.
     */
    public function loadDetail(Invoice $invoice): Invoice
    {
        return $invoice->load([
            'branch',
            'customer',
            'creator',
            'quotation',
            'performa',
            'deliveryNote',
            'items.itemStones',
        ]);
    }

    /**
     * Void a completed invoice and restore stock.
     */
    public function void(Invoice $invoice, string $reason, User $actor): Invoice
    {
        if ($invoice->status !== InvoiceStatus::COMPLETED) {
            throw ValidationException::withMessages([
                'reason' => 'Only a completed invoice can be voided.',
            ]);
        }

        if ((int) $invoice->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        return DB::transaction(function () use ($invoice, $reason, $actor): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with('items')->firstOrFail();

            if ($invoice->status !== InvoiceStatus::COMPLETED) {
                throw ValidationException::withMessages(['reason' => 'This invoice has already been voided.']);
            }

            // Settlement invoices already deducted stock at performa issue.
            // Voiding them restores on-memo / outstanding qty — never shelf stock.
            if ($invoice->performa_id) {
                $this->performaSettlementService->reverseVoid($invoice, $actor);
            } elseif ($invoice->delivery_note_id) {
                // The goods are still with the client: the delivery note opens again.
                $this->deliveryNoteService->reopenAfterInvoiceVoid($invoice, $actor);
            } else {
                foreach ($invoice->items as $line) {
                    if ($line->line_type === LineType::JEWELLERY_ITEM && $line->jewellery_item_id) {
                        $item = JewelleryItem::query()->lockForUpdate()->find($line->jewellery_item_id);
                        if ($item && $item->status === JewelleryItemStatus::SOLD) {
                            $this->jewelleryItemService->restoreFromVoid($item, $invoice, $actor);
                        }
                    } elseif ($line->line_type === LineType::LOOSE_STONE && $line->stone_id) {
                        $stone = Stone::query()->lockForUpdate()->find($line->stone_id);
                        if ($stone) {
                            $this->stoneStockService->apply(
                                $stone,
                                StoneMovementType::RETURNED_FROM_SALE,
                                (int) $line->quantity,
                                $actor,
                                $invoice,
                                "Void invoice {$invoice->invoice_number}"
                            );
                        }
                    }
                }
            }

            $old = ['status' => $invoice->status->value];
            $invoice->update([
                'status' => InvoiceStatus::VOIDED,
                'voided_by' => $actor->id,
                'voided_by_role' => $actor->role instanceof \BackedEnum ? $actor->role->value : (string) $actor->role,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $this->auditLog->log('invoice.voided', $invoice, $old, [
                'status' => InvoiceStatus::VOIDED->value,
                'void_reason' => $reason,
            ], $actor);

            // Voiding reverses a sale, so every revenue figure must drop
            // immediately. Cached dashboard numbers are retired here.
            DashboardStatsService::forgetBranch((int) $invoice->branch_id);

            return $invoice->fresh();
        });
    }
}
