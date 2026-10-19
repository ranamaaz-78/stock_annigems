<?php

namespace App\Services;

use App\Enums\DeliveryNoteStatus;
use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\LineType;
use App\Enums\MakingChargeType;
use App\Enums\PaymentMethod;
use App\Enums\StoneMovementType;
use App\Enums\PaymentStatus;
use App\Enums\TaxType;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\DeliveryNoteItemStone;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemStone;
use App\Models\JewelleryItem;
use App\Models\Stone;
use App\Models\User;
use App\Support\Settings\DocumentSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DeliveryNoteService
 *
 * Delivery notes (albarán): goods go to the client now, with prices and an
 * optional discount but no tax and no payment.
 *
 * Stock rules (same as a sale, see InvoiceConversionService):
 *   • Issue    jewellery → DELIVERED, loose stone → quantity out (DELIVERED).
 *              Stones set inside a jewellery piece are never touched.
 *   • Cancel   jewellery → AVAILABLE, loose stone → quantity back.
 *   • Invoice  jewellery → SOLD, loose stone → ledger row only; nothing is cut
 *              a second time because the goods already left at issue.
 */
class DeliveryNoteService
{
    public function __construct(
        private CodeSequenceService $codeSequence,
        private QuotationService $quotationService,
        private PricingCalculationService $pricing,
        private JewelleryItemService $jewelleryItemService,
        private StoneStockService $stoneStockService,
        private AuditLogService $auditLog,
        private PaymentService $payments,
    ) {}

    /**
     * Branch-scoped paginated list.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = DeliveryNote::query()
            ->with(['customer', 'creator', 'invoice'])
            ->withCount('items')
            ->orderByDesc('delivery_date')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('delivery_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('delivery_date', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($term): void {
                $q->where('delivery_note_number', 'like', "%{$term}%")
                    ->orWhere('customer_name_snapshot', 'like', "%{$term}%");
            });
        }

        return $query->paginate(per_page())->withQueryString();
    }

    /**
     * Load a note with everything show and print need.
     */
    public function loadDetail(DeliveryNote $note): DeliveryNote
    {
        return $note->load(['branch', 'customer', 'creator', 'invoice', 'items.itemStones', 'payments.creator']);
    }

    /**
     * Issue a delivery note from a POS workspace payload and take the stock out.
     *
     * @param  array<string, mixed>  $data  Validated workspace payload
     */
    public function create(array $data, User $actor): DeliveryNote
    {
        // A delivery note never carries tax, whatever the catalog says.
        $data['apply_tax'] = false;

        $prepared = $this->quotationService->prepareWorkspace($data, $actor);

        return DB::transaction(function () use ($prepared, $data, $actor): DeliveryNote {
            $this->quotationService->assertLinesAvailable($prepared['lines'], $actor);

            $customerId = $this->quotationService->resolveCustomerId($prepared['data'], $actor);
            $customer = $customerId ? Customer::query()->whereKey($customerId)->first() : null;
            $document = $prepared['document'];

            $note = DeliveryNote::query()->create([
                'branch_id' => $actor->branch_id,
                'delivery_note_number' => $this->codeSequence->nextDeliveryNoteNumber(),
                'customer_id' => $customerId,
                'customer_name_snapshot' => $customer?->name ?? 'Walk-in',
                'customer_phone_snapshot' => $customer?->phone,
                'customer_address_snapshot' => $customer?->address,
                'delivery_date' => $prepared['data']['quotation_date'] ?? now()->toDateString(),
                'subtotal' => $document['subtotal'],
                'discount_type' => DiscountType::from($document['discount_type']),
                'discount_value' => $document['discount_value'],
                'discount_amount' => $document['discount_amount'],
                // No tax on a delivery note: the total is the discounted subtotal.
                'grand_total' => $document['grand_total'],
                'status' => DeliveryNoteStatus::ISSUED,
                // Pending until a payment is recorded (below, or later from the note's page).
                'payment_status' => PaymentStatus::PENDING,
                'notes' => $prepared['data']['notes'] ?? null,
            ]);

            foreach ($prepared['lines'] as $index => $line) {
                $this->copyHydratedLine($note, $line, $index);
                $this->takeStockOut($line, $note, $actor);
            }

            // "Paid" in the POS records the note's total; the default is pending.
            $this->payments->applyChoice($note, ['payment_status' => $data['payment_status'] ?? PaymentStatus::PENDING->value] + $data, $actor);

            $this->auditLog->log('delivery_note.created', $note, null, [
                'delivery_note_number' => $note->delivery_note_number,
                'grand_total' => (string) $note->grand_total,
            ], $actor);

            return $note->fresh(['items.itemStones', 'branch', 'customer']);
        });
    }

    /**
     * Edit a delivery note nobody has paid yet, from the POS workspace payload
     * that made it. What it holds goes back to stock, the lines are replaced and
     * the new goods are taken out, so stock only moves by the difference.
     *
     * @param  array<string, mixed>  $data  Validated workspace payload
     */
    public function update(DeliveryNote $note, array $data, User $actor): DeliveryNote
    {
        // A delivery note never carries tax, whatever the catalog says.
        $data['apply_tax'] = false;

        $prepared = $this->quotationService->prepareWorkspace($data, $actor);

        return DB::transaction(function () use ($note, $prepared, $actor): DeliveryNote {
            $note = DeliveryNote::query()->whereKey($note->id)->lockForUpdate()->with('items')->firstOrFail();

            if ((int) $note->branch_id !== (int) $actor->branch_id) {
                abort(403);
            }

            if (! $note->canBeEdited()) {
                throw ValidationException::withMessages([
                    'delivery_note' => 'This delivery note can no longer be edited: a payment has been recorded or it is not open.',
                ]);
            }

            $this->returnGoods($note, $actor, "Delivery note {$note->delivery_note_number} edited");
            foreach ($note->items as $line) {
                $line->itemStones()->delete();
            }
            $note->items()->delete();

            $this->quotationService->assertLinesAvailable($prepared['lines'], $actor);

            $customerId = $this->quotationService->resolveCustomerId($prepared['data'], $actor);
            $customer = $customerId ? Customer::query()->whereKey($customerId)->first() : null;
            $document = $prepared['document'];

            $note->update([
                'customer_id' => $customerId,
                'customer_name_snapshot' => $customer?->name ?? 'Walk-in',
                'customer_phone_snapshot' => $customer?->phone,
                'customer_address_snapshot' => $customer?->address,
                'delivery_date' => $prepared['data']['quotation_date'] ?? $note->delivery_date,
                'subtotal' => $document['subtotal'],
                'discount_type' => DiscountType::from($document['discount_type']),
                'discount_value' => $document['discount_value'],
                'discount_amount' => $document['discount_amount'],
                'grand_total' => $document['grand_total'],
                'notes' => $prepared['data']['notes'] ?? null,
            ]);

            foreach ($prepared['lines'] as $index => $line) {
                $this->copyHydratedLine($note, $line, $index);
                $this->takeStockOut($line, $note, $actor);
            }

            $this->auditLog->log('delivery_note.updated', $note, null, [
                'delivery_note_number' => $note->delivery_note_number,
                'grand_total' => (string) $note->grand_total,
                'lines' => count($prepared['lines']),
            ], $actor);

            return $note->fresh(['items.itemStones', 'branch', 'customer']);
        });
    }

    /**
     * Put every delivered item of a note back on the shelf.
     */
    private function returnGoods(DeliveryNote $note, User $actor, string $reason): void
    {
        foreach ($note->items as $line) {
            if ($line->line_type === LineType::JEWELLERY_ITEM && $line->jewellery_item_id) {
                $item = JewelleryItem::query()->lockForUpdate()->find($line->jewellery_item_id);
                if ($item) {
                    $this->jewelleryItemService->restoreFromDelivery($item, $note->delivery_note_number, $actor);
                }
            } elseif ($line->line_type === LineType::LOOSE_STONE && $line->stone_id) {
                $stone = Stone::query()->lockForUpdate()->find($line->stone_id);
                if ($stone && $line->weight_grams !== null) {
                    $this->stoneStockService->applyWeight($stone, StoneMovementType::RETURNED_FROM_DELIVERY, round((float) $line->weight_grams, 3), $actor, $note, $reason);
                } elseif ($stone) {
                    $this->stoneStockService->apply($stone, StoneMovementType::RETURNED_FROM_DELIVERY, (int) $line->quantity, $actor, $note, $reason);
                }
            }
        }
    }

    /**
     * Cancel an open note and put every delivered item back on the shelf.
     */
    public function cancel(DeliveryNote $note, string $reason, User $actor): DeliveryNote
    {
        if (! $note->isOpen()) {
            throw ValidationException::withMessages([
                'reason' => 'Only an issued delivery note can be cancelled.',
            ]);
        }

        if ((int) $note->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        return DB::transaction(function () use ($note, $reason, $actor): DeliveryNote {
            $note = DeliveryNote::query()->whereKey($note->id)->lockForUpdate()->with('items')->firstOrFail();

            if (! $note->isOpen()) {
                throw ValidationException::withMessages(['reason' => 'This delivery note is no longer open.']);
            }

            if ($note->payments()->exists()) {
                throw ValidationException::withMessages(['reason' => 'This delivery note has been paid and cannot be cancelled.']);
            }

            foreach ($note->items as $line) {
                if ($line->line_type === LineType::JEWELLERY_ITEM && $line->jewellery_item_id) {
                    $item = JewelleryItem::query()->lockForUpdate()->find($line->jewellery_item_id);
                    if ($item) {
                        $this->jewelleryItemService->restoreFromDelivery($item, $note->delivery_note_number, $actor);
                    }
                } elseif ($line->line_type === LineType::LOOSE_STONE && $line->stone_id) {
                    $stone = Stone::query()->lockForUpdate()->find($line->stone_id);
                    if ($stone && $line->weight_grams !== null) {
                        $this->stoneStockService->applyWeight(
                            $stone,
                            StoneMovementType::RETURNED_FROM_DELIVERY,
                            round((float) $line->weight_grams, 3),
                            $actor,
                            $note,
                            "Cancelled delivery note {$note->delivery_note_number}"
                        );
                    } elseif ($stone) {
                        $this->stoneStockService->apply(
                            $stone,
                            StoneMovementType::RETURNED_FROM_DELIVERY,
                            (int) $line->quantity,
                            $actor,
                            $note,
                            "Cancelled delivery note {$note->delivery_note_number}"
                        );
                    }
                }
            }

            $old = ['status' => $note->status->value];
            $note->update([
                'status' => DeliveryNoteStatus::CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_by_role' => $actor->role instanceof \BackedEnum ? $actor->role->value : (string) $actor->role,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $this->auditLog->log('delivery_note.cancelled', $note, $old, [
                'status' => DeliveryNoteStatus::CANCELLED->value,
                'cancel_reason' => $reason,
            ], $actor);

            return $note->fresh();
        });
    }

    /**
     * Turn an open note into a completed invoice.
     *
     * The note's lines and discount are copied as they are. Tax, which the
     * note never had, is chosen at this moment: the IVA and recargo rates
     * named in $payment (resolved from the SuperAdmin list). Stock is not
     * cut again.
     *
     * @param  array{payment_status?: string, payment_method?: string|null, payment_reference?: string|null, tax_rate_uuid?: string|null, recargo_rate_uuid?: string|null}  $payment
     */
    public function convertToInvoice(DeliveryNote $note, array $payment, User $actor): Invoice
    {
        if (! $note->isOpen()) {
            throw ValidationException::withMessages([
                'delivery_note' => 'Only an issued delivery note can be turned into an invoice.',
            ]);
        }

        if ((int) $note->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        return DB::transaction(function () use ($note, $payment, $actor): Invoice {
            $note = DeliveryNote::query()
                ->whereKey($note->id)
                ->lockForUpdate()
                ->with(['items.itemStones', 'customer'])
                ->firstOrFail();

            if (! $note->isOpen()) {
                throw ValidationException::withMessages(['delivery_note' => 'This delivery note is no longer open.']);
            }

            // IVA and recargo are chosen now (the note had none). A blank IVA
            // means none; leaving the key out takes the default IVA.
            $taxChoice = DocumentSettings::applyToPayload([
                'tax_rate_uuid' => $payment['tax_rate_uuid'] ?? null,
                'recargo_rate_uuid' => $payment['recargo_rate_uuid'] ?? null,
            ]);
            $document = $this->pricing->calculateDocument(
                $note->items->map(fn (DeliveryNoteItem $line): array => ['line_total' => $line->line_total])->all(),
                [
                    'discount_type' => $note->discount_type->value,
                    'discount_value' => $note->discount_value,
                    'tax_type' => $taxChoice['tax_type'],
                    'tax_rate' => $taxChoice['tax_rate'],
                    'tax_name' => $taxChoice['tax_name'],
                    'recargo_rate' => $taxChoice['recargo_rate'],
                    'recargo_name' => $taxChoice['recargo_name'],
                ]
            );

            $invoice = Invoice::query()->create([
                'branch_id' => $note->branch_id,
                'invoice_number' => $this->codeSequence->nextInvoiceNumber(),
                'verification_token' => (setting('invoice_qr_enabled') && setting('public_invoice_verification_enabled'))
                    ? Str::random(48)
                    : null,
                'quotation_id' => null,
                'delivery_note_id' => $note->id,
                'customer_id' => $note->customer_id,
                'customer_name_snapshot' => $note->customer_name_snapshot,
                'customer_phone_snapshot' => $note->customer_phone_snapshot,
                'invoice_date' => now()->toDateString(),
                'subtotal' => $document['subtotal'],
                'discount_type' => DiscountType::from($document['discount_type']),
                'discount_value' => $document['discount_value'],
                'discount_amount' => $document['discount_amount'],
                'tax_type' => TaxType::from($document['tax_type']),
                'tax_rate' => $document['tax_rate'],
                'tax_amount' => $document['tax_amount'],
                'tax_name' => $document['tax_name'],
                'recargo_name' => $document['recargo_name'],
                'recargo_rate' => $document['recargo_rate'],
                'recargo_amount' => $document['recargo_amount'],
                'grand_total' => $document['grand_total'],
                'payment_method' => null,
                'payment_reference' => null,
                'payment_status' => PaymentStatus::PENDING,
                'status' => InvoiceStatus::COMPLETED,
                'notes' => $note->notes,
            ]);

            foreach ($note->items as $line) {
                $this->copyLineToInvoice($invoice, $line);
                $this->markSold($line, $invoice, $note, $actor);
            }

            // What the client already paid on the note counts toward the invoice;
            // only the difference (the tax the note never had) is still due.
            $note->load('payments');
            $this->payments->copyPayments($note, $invoice);
            $invoice->unsetRelation('payments');
            $due = $invoice->balanceDue();

            if (($payment['payment_status'] ?? 'paid') === PaymentStatus::PAID->value && $due > 0.005) {
                if (blank($payment['payment_method'] ?? null)) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'Choose how the '.setting_money($due).' difference is being paid.',
                    ]);
                }

                $this->payments->record($invoice, (string) $payment['payment_method'], $payment['payment_reference'] ?? null, $actor);
            }

            $old = ['status' => $note->status->value];
            $note->update([
                'status' => DeliveryNoteStatus::INVOICED,
                'invoice_id' => $invoice->id,
            ]);

            $this->auditLog->log('invoice.created', $invoice, null, [
                'invoice_number' => $invoice->invoice_number,
                'delivery_note_number' => $note->delivery_note_number,
                'source' => 'delivery_note',
                'grand_total' => (string) $invoice->grand_total,
            ], $actor);

            $this->auditLog->log('delivery_note.invoiced', $note, $old, [
                'status' => DeliveryNoteStatus::INVOICED->value,
                'invoice_number' => $invoice->invoice_number,
            ], $actor);

            DashboardStatsService::forgetBranch((int) $invoice->branch_id);

            return $invoice->fresh(['items.itemStones', 'branch', 'customer']);
        });
    }

    /**
     * Voiding an invoice that came from a note sends the goods back to the
     * client's side, not to the shelf: the note is open again.
     *
     * Called by InvoiceService::void inside its transaction.
     */
    public function reopenAfterInvoiceVoid(Invoice $invoice, User $actor): void
    {
        $note = DeliveryNote::query()
            ->whereKey($invoice->delivery_note_id)
            ->lockForUpdate()
            ->first();

        if (! $note) {
            return;
        }

        foreach ($invoice->items as $line) {
            if ($line->line_type === LineType::JEWELLERY_ITEM && $line->jewellery_item_id) {
                $item = JewelleryItem::query()->lockForUpdate()->find($line->jewellery_item_id);
                if ($item) {
                    $this->jewelleryItemService->restoreToDeliveredFromVoid($item, $invoice, $actor);
                }
            } elseif ($line->line_type === LineType::LOOSE_STONE && $line->stone_id) {
                $stone = Stone::query()->lockForUpdate()->find($line->stone_id);
                if ($stone) {
                    $this->stoneStockService->recordUnchanged(
                        $stone,
                        StoneMovementType::DELIVERED,
                        $actor,
                        $invoice,
                        "Invoice {$invoice->invoice_number} voided: back on delivery note {$note->delivery_note_number}"
                    );
                }
            }
        }

        $note->update([
            'status' => DeliveryNoteStatus::ISSUED,
            'invoice_id' => null,
        ]);
    }

    /**
     * Take the goods of one hydrated line out of stock.
     *
     * @param  array<string, mixed>  $line
     */
    private function takeStockOut(array $line, DeliveryNote $note, User $actor): void
    {
        if (LineType::from((string) $line['line_type']) === LineType::JEWELLERY_ITEM) {
            $item = JewelleryItem::query()->lockForUpdate()->findOrFail($line['jewellery_item_id']);
            $this->jewelleryItemService->markDelivered($item, $note->delivery_note_number, $actor);

            return;
        }

        $stone = Stone::query()->lockForUpdate()->findOrFail($line['stone_id']);

        if ($stone->isWeighed()) {
            $this->stoneStockService->applyWeight(
                $stone,
                StoneMovementType::DELIVERED,
                -round((float) ($line['weight_grams'] ?? 0), 3),
                $actor,
                $note,
                "Delivered on {$note->delivery_note_number}"
            );

            return;
        }

        $this->stoneStockService->apply(
            $stone,
            StoneMovementType::DELIVERED,
            -max(1, (int) ($line['quantity'] ?? 1)),
            $actor,
            $note,
            "Delivered on {$note->delivery_note_number}"
        );
    }

    /**
     * Record the sale of a delivered line when its note becomes an invoice.
     */
    private function markSold(DeliveryNoteItem $line, Invoice $invoice, DeliveryNote $note, User $actor): void
    {
        if ($line->line_type === LineType::JEWELLERY_ITEM) {
            $item = JewelleryItem::query()->lockForUpdate()->findOrFail($line->jewellery_item_id);
            $this->jewelleryItemService->markSold($item, $invoice, $actor);

            return;
        }

        $stone = Stone::query()->lockForUpdate()->findOrFail($line->stone_id);
        $this->stoneStockService->recordUnchanged(
            $stone,
            StoneMovementType::SOLD,
            $actor,
            $invoice,
            "Sold on invoice {$invoice->invoice_number} (delivered on {$note->delivery_note_number})"
        );
    }

    /**
     * Copy a hydrated, priced workspace line onto the note.
     *
     * @param  array<string, mixed>  $line
     */
    private function copyHydratedLine(DeliveryNote $note, array $line, int $index): DeliveryNoteItem
    {
        $lineType = LineType::from((string) $line['line_type']);

        $item = DeliveryNoteItem::query()->create([
            'delivery_note_id' => $note->id,
            'line_type' => $lineType,
            'jewellery_item_id' => $lineType === LineType::JEWELLERY_ITEM ? ($line['jewellery_item_id'] ?? null) : null,
            'stone_id' => $lineType === LineType::LOOSE_STONE ? ($line['stone_id'] ?? null) : null,
            'item_code_snapshot' => $line['item_code_snapshot'] ?? '',
            'name_snapshot' => $line['name_snapshot'] ?? '',
            'category_snapshot' => $line['category_snapshot'] ?? null,
            'metal_snapshot' => $line['metal_snapshot'] ?? null,
            'purity_snapshot' => $line['purity_snapshot'] ?? null,
            'gross_weight_snapshot' => $line['gross_weight_snapshot'] ?? null,
            'net_weight_snapshot' => $line['net_weight_snapshot'] ?? null,
            'quantity' => (int) ($line['quantity'] ?? 1),
            'weight_grams' => $line['weight_grams'] ?? null,
            'gold_rate_applied' => $line['gold_rate_applied'] ?? 0,
            'gold_value' => $line['gold_value'] ?? 0,
            'making_charge_type' => $line['making_charge_type'] ?? MakingChargeType::NONE->value,
            'making_charge_value' => $line['making_charge_value'] ?? 0,
            'making_charges' => $line['making_charges'] ?? 0,
            'stone_value' => $line['stone_value'] ?? 0,
            'line_total' => $line['line_total'] ?? 0,
            'sort_order' => $index,
        ]);

        foreach ($line['stones'] ?? [] as $stone) {
            DeliveryNoteItemStone::query()->create([
                'delivery_note_item_id' => $item->id,
                'jewellery_item_stone_id' => $stone['jewellery_item_stone_id'] ?? null,
                'stone_name_snapshot' => $stone['stone_name_snapshot'] ?? '',
                'stone_type_snapshot' => $stone['stone_type_snapshot'] ?? '',
                'carat_snapshot' => $stone['carat_snapshot'] ?? 0,
                'shape_snapshot' => $stone['shape_snapshot'] ?? null,
                'color_snapshot' => $stone['color_snapshot'] ?? null,
                'clarity_snapshot' => $stone['clarity_snapshot'] ?? null,
                'quantity' => (int) ($stone['quantity'] ?? 1),
                'price_per_piece' => $stone['price_per_piece'] ?? 0,
                'total_price' => $stone['total_price'] ?? 0,
            ]);
        }

        return $item;
    }

    /**
     * Copy a note line, with its set stones, onto an invoice unchanged.
     */
    private function copyLineToInvoice(Invoice $invoice, DeliveryNoteItem $line): InvoiceItem
    {
        $invoiceItem = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_type' => $line->line_type,
            'jewellery_item_id' => $line->jewellery_item_id,
            'stone_id' => $line->stone_id,
            'item_code_snapshot' => $line->item_code_snapshot,
            'name_snapshot' => $line->name_snapshot,
            'category_snapshot' => $line->category_snapshot,
            'metal_snapshot' => $line->metal_snapshot,
            'purity_snapshot' => $line->purity_snapshot,
            'gross_weight_snapshot' => $line->gross_weight_snapshot,
            'net_weight_snapshot' => $line->net_weight_snapshot,
            'quantity' => $line->quantity,
            'weight_grams' => $line->weight_grams,
            'gold_rate_applied' => $line->gold_rate_applied,
            'gold_value' => $line->gold_value,
            'making_charge_type' => $line->making_charge_type,
            'making_charge_value' => $line->making_charge_value,
            'making_charges' => $line->making_charges,
            'stone_value' => $line->stone_value,
            'line_total' => $line->line_total,
            'sort_order' => $line->sort_order,
        ]);

        foreach ($line->itemStones as $stone) {
            InvoiceItemStone::query()->create([
                'invoice_item_id' => $invoiceItem->id,
                'jewellery_item_stone_id' => $stone->jewellery_item_stone_id,
                'stone_name_snapshot' => $stone->stone_name_snapshot,
                'stone_type_snapshot' => $stone->stone_type_snapshot,
                'carat_snapshot' => $stone->carat_snapshot,
                'shape_snapshot' => $stone->shape_snapshot,
                'color_snapshot' => $stone->color_snapshot,
                'clarity_snapshot' => $stone->clarity_snapshot,
                'quantity' => $stone->quantity,
                'price_per_piece' => $stone->price_per_piece,
                'total_price' => $stone->total_price,
            ]);
        }

        return $invoiceItem;
    }
}
