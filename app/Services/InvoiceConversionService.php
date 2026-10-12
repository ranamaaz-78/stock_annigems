<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\MakingChargeType;
use App\Enums\PaymentMethod;
use App\Enums\QuotationStatus;
use App\Enums\StoneMovementType;
use App\Enums\TaxType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemStone;
use App\Models\JewelleryItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Stone;
use App\Models\User;
use App\Support\Settings\DocumentSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * InvoiceConversionService
 *
 * Issues an immutable completed invoice inside one transaction, either by
 * converting a draft quotation or by billing the quotation workspace
 * directly (no quotation row).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CRITICAL STOCK RULE — DO NOT DEDUCT STONES TWICE
 * ═══════════════════════════════════════════════════════════════════════════
 * When a jewellery item was manufactured (Module 4), its constituent stones
 * were ALREADY deducted from loose-stone inventory via USED_IN_ITEM movements.
 * Those stones physically left the drawer and went into the piece.
 *
 * On sale:
 *   • Jewellery line → set item status to SOLD + write jewellery movement ONLY.
 *   • Loose stone line → deduct quantity via StoneStockService with SOLD movement.
 *
 * NEVER touch jewellery_item_stones or deduct internal stones at sale time.
 * Doing so would double-count and drive stone inventory negative or wrong.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class InvoiceConversionService
{
    public function __construct(
        private CodeSequenceService $codeSequence,
        private JewelleryItemService $jewelleryItemService,
        private StoneStockService $stoneStockService,
        private AuditLogService $auditLog,
        private QuotationService $quotationService,
        private QuotationDraftService $draftService,
        private QuotationStockService $quotationStock,
    ) {}

    /**
     * Convert quotation to invoice with full re-validation and stock updates.
     *
     * @param  array{payment_method: string, payment_reference?: string|null, customer_id?: int|null, customer_uuid?: string|null}  $payment
     */
    public function convert(Quotation $quotation, array $payment, User $actor): Invoice
    {
        if ($quotation->status !== QuotationStatus::DRAFT) {
            throw ValidationException::withMessages([
                'quotation' => 'Only draft quotations can be converted.',
            ]);
        }

        if ((int) $quotation->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        $this->assertDiscountWithinCeiling($quotation);

        return DB::transaction(function () use ($quotation, $payment, $actor): Invoice {
            $quotation = Quotation::query()
                ->whereKey($quotation->id)
                ->lockForUpdate()
                ->with(['items.itemStones', 'customer', 'branch'])
                ->firstOrFail();

            if ($quotation->status !== QuotationStatus::DRAFT) {
                throw ValidationException::withMessages(['quotation' => 'This quotation has already been converted or cancelled.']);
            }

            $this->revalidateLines($quotation, $actor);

            $customerName = $quotation->customer?->name ?? $quotation->walkin_name ?? 'Walk-in';
            $customerPhone = $quotation->customer?->phone ?? $quotation->walkin_phone;

            $invoice = Invoice::query()->create([
                'branch_id' => $quotation->branch_id,
                'invoice_number' => $this->codeSequence->nextInvoiceNumber(),
                'verification_token' => (setting('invoice_qr_enabled') && setting('public_invoice_verification_enabled'))
                    ? Str::random(48)
                    : null,
                'quotation_id' => $quotation->id,
                'customer_id' => $quotation->customer_id,
                'customer_name_snapshot' => $customerName,
                'customer_phone_snapshot' => $customerPhone,
                'invoice_date' => now()->toDateString(),
                'subtotal' => $quotation->subtotal,
                'discount_type' => $quotation->discount_type,
                'discount_value' => $quotation->discount_value,
                'discount_amount' => $quotation->discount_amount,
                'tax_type' => $quotation->tax_type,
                'tax_rate' => $quotation->tax_rate,
                'tax_amount' => $quotation->tax_amount,
                'grand_total' => $quotation->grand_total,
                'payment_method' => PaymentMethod::from($payment['payment_method']),
                'payment_reference' => $payment['payment_reference'] ?? null,
                'status' => InvoiceStatus::COMPLETED,
                'notes' => $quotation->notes,
            ]);

            foreach ($quotation->items as $qItem) {
                $this->copyLineToInvoice($invoice, $qItem);
                $this->applyStockMovement($qItem, $invoice, $actor, $quotation);
            }

            $quotation->update([
                'status' => QuotationStatus::CONVERTED,
                'converted_invoice_id' => $invoice->id,
            ]);

            $this->auditLog->log('invoice.created', $invoice, null, [
                'invoice_number' => $invoice->invoice_number,
                'quotation_number' => $quotation->quotation_number,
                'grand_total' => (string) $invoice->grand_total,
            ], $actor);

            $this->auditLog->log('quotation.converted', $quotation, ['status' => QuotationStatus::DRAFT->value], [
                'status' => QuotationStatus::CONVERTED->value,
                'invoice_number' => $invoice->invoice_number,
            ], $actor);

            // A new sale moves every revenue card on the branch dashboard and
            // the SuperAdmin consolidated view. Retire the cached figures now
            // rather than letting the operator see a stale total after billing.
            DashboardStatsService::forgetBranch((int) $invoice->branch_id);

            return $invoice->fresh(['items.itemStones', 'branch', 'customer']);
        });
    }

    /**
     * Issue a completed invoice from the quotation workspace without writing
     * a quotation. quotation_id stays null; stock rules match convert().
     *
     * @param  array<string, mixed>  $data  Validated workspace payload
     * @param  array{payment_method: string, payment_reference?: string|null}  $payment
     */
    public function createFromWorkspace(array $data, array $payment, User $actor): Invoice
    {
        $prepared = $this->quotationService->prepareWorkspace($data, $actor);

        return DB::transaction(function () use ($prepared, $payment, $actor): Invoice {
            $this->quotationService->assertLinesAvailable($prepared['lines'], $actor);

            $customerId = $this->quotationService->resolveCustomerId($prepared['data'], $actor);
            $customer = $customerId
                ? Customer::query()->whereKey($customerId)->first()
                : null;

            $document = $prepared['document'];

            $invoice = Invoice::query()->create([
                'branch_id' => $actor->branch_id,
                'invoice_number' => $this->codeSequence->nextInvoiceNumber(),
                'verification_token' => (setting('invoice_qr_enabled') && setting('public_invoice_verification_enabled'))
                    ? Str::random(48)
                    : null,
                // Direct issue: no quotation was ever saved.
                'quotation_id' => null,
                'customer_id' => $customerId,
                'customer_name_snapshot' => $customer?->name ?? 'Walk-in',
                'customer_phone_snapshot' => $customer?->phone,
                'invoice_date' => now()->toDateString(),
                'subtotal' => $document['subtotal'],
                'discount_type' => DiscountType::from($document['discount_type']),
                'discount_value' => $document['discount_value'],
                'discount_amount' => $document['discount_amount'],
                'tax_type' => TaxType::from($document['tax_type']),
                'tax_rate' => $document['tax_rate'],
                'tax_amount' => $document['tax_amount'],
                'grand_total' => $document['grand_total'],
                'payment_method' => PaymentMethod::from($payment['payment_method']),
                'payment_reference' => $payment['payment_reference'] ?? null,
                'status' => InvoiceStatus::COMPLETED,
                'notes' => $prepared['data']['notes'] ?? null,
            ]);

            foreach ($prepared['lines'] as $index => $line) {
                $this->copyHydratedLineToInvoice($invoice, $line, $index);
                $this->applyStockFromHydratedLine($line, $invoice, $actor);
            }

            $this->auditLog->log('invoice.created', $invoice, null, [
                'invoice_number' => $invoice->invoice_number,
                'source' => 'workspace',
                'grand_total' => (string) $invoice->grand_total,
            ], $actor);

            // Autosave would otherwise restore the billed lines on next visit.
            $this->draftService->discard($actor);

            DashboardStatsService::forgetBranch((int) $invoice->branch_id);

            return $invoice->fresh(['items.itemStones', 'branch', 'customer']);
        });
    }

    /**
     * Re-check every line inside the transaction with row locks.
     */
    private function revalidateLines(Quotation $quotation, User $actor): void
    {
        foreach ($quotation->items as $index => $line) {
            $lineNum = $index + 1;
            $code = $line->item_code_snapshot;

            if ($line->line_type === LineType::JEWELLERY_ITEM) {
                $item = JewelleryItem::query()
                    ->where('branch_id', $actor->branch_id)
                    ->whereKey($line->jewellery_item_id)
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw ValidationException::withMessages([
                        'lines' => "Line {$lineNum}: {$code} was not found in this branch.",
                    ]);
                }

                // A quotation save already reserved the piece. AVAILABLE is
                // only still valid for drafts created before that hold existed.
                if (! in_array($item->status, [JewelleryItemStatus::AVAILABLE, JewelleryItemStatus::RESERVED], true)) {
                    throw ValidationException::withMessages([
                        'lines' => "Line {$lineNum}: {$code} has already been sold or is not available.",
                    ]);
                }
            } else {
                $stone = Stone::query()
                    ->where('branch_id', $actor->branch_id)
                    ->whereKey($line->stone_id)
                    ->lockForUpdate()
                    ->first();

                $qty = (int) $line->quantity;
                $held = $stone
                    ? $this->quotationStock->reservedStoneQuantity($quotation, (int) $stone->id)
                    : 0;
                $available = $stone ? (int) $stone->quantity + $held : 0;
                if (! $stone || $available < $qty) {
                    throw ValidationException::withMessages([
                        'lines' => "Line {$lineNum}: {$code} has only {$available} in stock, {$qty} were quoted.",
                    ]);
                }
            }
        }
    }

    /**
     * Copy quotation line snapshot to invoice line.
     */
    private function copyLineToInvoice(Invoice $invoice, QuotationItem $qItem): InvoiceItem
    {
        $invItem = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_type' => $qItem->line_type,
            'jewellery_item_id' => $qItem->jewellery_item_id,
            'stone_id' => $qItem->stone_id,
            'item_code_snapshot' => $qItem->item_code_snapshot,
            'name_snapshot' => $qItem->name_snapshot,
            'category_snapshot' => $qItem->category_snapshot,
            'metal_snapshot' => $qItem->metal_snapshot,
            'purity_snapshot' => $qItem->purity_snapshot,
            'gross_weight_snapshot' => $qItem->gross_weight_snapshot,
            'net_weight_snapshot' => $qItem->net_weight_snapshot,
            'quantity' => $qItem->quantity,
            'gold_rate_applied' => $qItem->gold_rate_applied,
            'gold_value' => $qItem->gold_value,
            'making_charge_type' => $qItem->making_charge_type,
            'making_charge_value' => $qItem->making_charge_value,
            'making_charges' => $qItem->making_charges,
            'stone_value' => $qItem->stone_value,
            'line_total' => $qItem->line_total,
            'sort_order' => $qItem->sort_order,
        ]);

        foreach ($qItem->itemStones as $qStone) {
            InvoiceItemStone::query()->create([
                'invoice_item_id' => $invItem->id,
                'jewellery_item_stone_id' => $qStone->jewellery_item_stone_id,
                'stone_name_snapshot' => $qStone->stone_name_snapshot,
                'stone_type_snapshot' => $qStone->stone_type_snapshot,
                'carat_snapshot' => $qStone->carat_snapshot,
                'shape_snapshot' => $qStone->shape_snapshot,
                'color_snapshot' => $qStone->color_snapshot,
                'clarity_snapshot' => $qStone->clarity_snapshot,
                'quantity' => $qStone->quantity,
                'price_per_piece' => $qStone->price_per_piece,
                'total_price' => $qStone->total_price,
            ]);
        }

        return $invItem;
    }

    /**
     * Apply stock movement per line type. See class docblock — never deduct internal stones.
     *
     * Loose stones already cut on quotation save stay cut; we only write a
     * SOLD ledger row so the invoice is visible. Legacy drafts (no hold)
     * still deduct here.
     */
    private function applyStockMovement(QuotationItem $line, Invoice $invoice, User $actor, Quotation $quotation): void
    {
        if ($line->line_type === LineType::JEWELLERY_ITEM) {
            $item = JewelleryItem::query()->lockForUpdate()->findOrFail($line->jewellery_item_id);
            $this->jewelleryItemService->markSold($item, $invoice, $actor);

            return;
        }

        $stone = Stone::query()->lockForUpdate()->findOrFail($line->stone_id);
        $qty = (int) $line->quantity;
        $held = $this->quotationStock->reservedStoneQuantity($quotation, (int) $stone->id);

        if ($held >= $qty) {
            $this->stoneStockService->recordUnchanged(
                $stone,
                StoneMovementType::SOLD,
                $actor,
                $invoice,
                "Sold on invoice {$invoice->invoice_number} (held on {$quotation->quotation_number})"
            );

            return;
        }

        $this->stoneStockService->apply(
            $stone,
            StoneMovementType::SOLD,
            -$qty,
            $actor,
            $invoice,
            "Sold on invoice {$invoice->invoice_number}"
        );
    }

    /**
     * Copy a hydrated workspace line (already priced) onto the invoice.
     *
     * @param  array<string, mixed>  $line
     */
    private function copyHydratedLineToInvoice(Invoice $invoice, array $line, int $index): InvoiceItem
    {
        $lineType = LineType::from((string) $line['line_type']);

        $invItem = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
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
            InvoiceItemStone::query()->create([
                'invoice_item_id' => $invItem->id,
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

        return $invItem;
    }

    /**
     * Apply stock for a hydrated workspace line. Same rule as convert():
     * jewellery → SOLD movement only; loose stone → SOLD quantity deduct.
     * Never touch jewellery_item_stones.
     *
     * @param  array<string, mixed>  $line
     */
    private function applyStockFromHydratedLine(array $line, Invoice $invoice, User $actor): void
    {
        $lineType = LineType::from((string) $line['line_type']);

        if ($lineType === LineType::JEWELLERY_ITEM) {
            $item = JewelleryItem::query()->lockForUpdate()->findOrFail($line['jewellery_item_id']);
            $this->jewelleryItemService->markSold($item, $invoice, $actor);

            return;
        }

        $stone = Stone::query()->lockForUpdate()->findOrFail($line['stone_id']);
        $this->stoneStockService->apply(
            $stone,
            StoneMovementType::SOLD,
            -((int) ($line['quantity'] ?? 1)),
            $actor,
            $invoice,
            "Sold on invoice {$invoice->invoice_number}"
        );
    }

    /**
     * A quotation saved before the ceiling was enforced cannot become an
     * invoice while it still exceeds max_discount_percentage.
     */
    private function assertDiscountWithinCeiling(Quotation $quotation): void
    {
        if (! setting('discount_enabled')) {
            return;
        }

        $max = DocumentSettings::maxDiscountPercentage();
        $type = $quotation->discount_type;

        if ($type === DiscountType::PERCENTAGE && (float) $quotation->discount_value > $max) {
            throw ValidationException::withMessages([
                'discount_value' => 'Discount cannot exceed '.$max.'%. Edit the quotation before converting.',
            ]);
        }

        $subtotal = (float) $quotation->subtotal;
        if ($type === DiscountType::FIXED && $subtotal > 0) {
            $percent = ((float) $quotation->discount_amount / $subtotal) * 100;
            if ($percent > $max + 0.009) {
                throw ValidationException::withMessages([
                    'discount_value' => 'Discount cannot exceed '.$max.'%. Edit the quotation before converting.',
                ]);
            }
        }
    }
}
