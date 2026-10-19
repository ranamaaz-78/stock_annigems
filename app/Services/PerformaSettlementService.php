<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\MakingChargeType;
use App\Enums\PaymentMethod;
use App\Enums\StoneMovementType;
use App\Enums\TaxType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemStone;
use App\Models\JewelleryItem;
use App\Models\Performa;
use App\Models\PerformaItem;
use App\Models\PerformaSettlement;
use App\Models\Stone;
use App\Models\User;
use App\Support\Settings\DocumentSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PerformaSettlementService
 *
 * Turns unpaid performa qty into a completed invoice. Jewellery already on
 * memo is marked sold; loose stones already issued get a zero-qty settle
 * ledger — never a second SOLD deduct.
 */
class PerformaSettlementService
{
    public function __construct(
        private CodeSequenceService $codeSequence,
        private PricingCalculationService $pricing,
        private JewelleryItemService $jewelleryItemService,
        private StoneStockService $stoneStockService,
        private PerformaService $performaService,
        private AuditLogService $auditLog,
        private PaymentService $payments,
    ) {}

    /**
     * Settle selected outstanding lines and issue a completed invoice.
     *
     * @param  array<string, mixed>  $data
     */
    public function settle(Performa $performa, array $data, User $actor): Invoice
    {
        if ((int) $performa->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        if (! $performa->isOpen()) {
            throw ValidationException::withMessages([
                'performa' => 'This performa has no unpaid goods to settle.',
            ]);
        }

        return DB::transaction(function () use ($performa, $data, $actor): Invoice {
            $performa = Performa::query()
                ->whereKey($performa->id)
                ->lockForUpdate()
                ->with(['items.jewelleryItem.itemStones', 'creditor'])
                ->firstOrFail();

            if (! $performa->status->isOpen()) {
                throw ValidationException::withMessages([
                    'performa' => 'This performa has no unpaid goods to settle.',
                ]);
            }

            $selections = $this->validatedSelections($performa, $data);
            $pricedLines = $this->buildPricedLines($selections);
            // IVA and recargo are the cashier's choice: a blank IVA means none,
            // and leaving the key out takes the default IVA.
            $taxChoice = DocumentSettings::applyToPayload(array_intersect_key(
                $data,
                ['tax_rate_uuid' => 1, 'recargo_rate_uuid' => 1]
            ));
            $result = $this->pricing->recalculate($pricedLines, [
                'discount_type' => DiscountType::NONE->value,
                'discount_value' => 0,
                'tax_type' => $taxChoice['tax_type'],
                'tax_rate' => $taxChoice['tax_rate'],
                'tax_name' => $taxChoice['tax_name'],
                'recargo_rate' => $taxChoice['recargo_rate'],
                'recargo_name' => $taxChoice['recargo_name'],
            ]);

            $invoice = Invoice::query()->create([
                'branch_id' => $performa->branch_id,
                'invoice_number' => $this->codeSequence->nextInvoiceNumber(),
                'verification_token' => (setting('invoice_qr_enabled') && setting('public_invoice_verification_enabled'))
                    ? Str::random(48)
                    : null,
                'quotation_id' => null,
                'performa_id' => $performa->id,
                // Settlement invoices snapshot the creditor. They never create
                // or attach a sales customer — customer_id stays null.
                'customer_id' => null,
                'customer_name_snapshot' => $performa->creditor?->name ?? 'Creditor',
                'customer_phone_snapshot' => $performa->creditor?->phone,
                'invoice_date' => now()->toDateString(),
                'subtotal' => $result['document']['subtotal'],
                'discount_type' => DiscountType::NONE,
                'discount_value' => 0,
                'discount_amount' => $result['document']['discount_amount'],
                'tax_type' => TaxType::from((string) $result['document']['tax_type']),
                'tax_rate' => $result['document']['tax_rate'],
                'tax_amount' => $result['document']['tax_amount'],
                'tax_name' => $result['document']['tax_name'],
                'recargo_name' => $result['document']['recargo_name'],
                'recargo_rate' => $result['document']['recargo_rate'],
                'recargo_amount' => $result['document']['recargo_amount'],
                'grand_total' => $result['document']['grand_total'],
                'payment_method' => PaymentMethod::from((string) $data['payment_method']),
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_status' => \App\Enums\PaymentStatus::PENDING,
                'status' => InvoiceStatus::COMPLETED,
                'notes' => $data['notes'] ?? ('Settlement of '.$performa->performa_number),
            ]);

            foreach ($result['lines'] as $index => $line) {
                $this->writeInvoiceLine($invoice, $line, $index);
            }

            // A settlement is always paid in full as it is made.
            $this->payments->record($invoice, (string) $data['payment_method'], $data['payment_reference'] ?? null, $actor);

            PerformaSettlement::query()->create([
                'performa_id' => $performa->id,
                'invoice_id' => $invoice->id,
                'payment_method' => $data['payment_method'],
                'payment_reference' => $data['payment_reference'] ?? null,
                'settled_at' => now(),
                'created_by' => $actor->id,
                'created_by_role' => $actor->role instanceof \BackedEnum ? $actor->role->value : (string) $actor->role,
            ]);

            foreach ($selections as $selection) {
                $this->applySettlementStock($selection, $invoice, $performa, $actor);
                $item = $selection['item'];
                if ($item->isWeighed()) {
                    $item->weight_settled = round((float) $item->weight_settled + (float) $selection['quantity'], 3);
                } else {
                    $item->quantity_settled += $selection['quantity'];
                }
                $item->save();
            }

            $this->performaService->refreshStatus($performa);

            $this->auditLog->log('invoice.created', $invoice, null, [
                'invoice_number' => $invoice->invoice_number,
                'source' => 'performa',
                'performa_number' => $performa->performa_number,
                'grand_total' => (string) $invoice->grand_total,
            ], $actor);

            $this->auditLog->log('performa.settled', $performa, null, [
                'invoice_number' => $invoice->invoice_number,
                'lines' => count($selections),
            ], $actor);

            DashboardStatsService::forgetBranch((int) $invoice->branch_id);

            return $invoice->fresh(['items', 'branch', 'customer']);
        });
    }

    /**
     * Reverse a settlement invoice void: jewellery back to on_memo, stones
     * stay out (already issued), settled qty reduced.
     */
    public function reverseVoid(Invoice $invoice, User $actor): void
    {
        $settlement = PerformaSettlement::query()
            ->where('invoice_id', $invoice->id)
            ->first();

        if (! $settlement) {
            return;
        }

        $performa = Performa::query()
            ->whereKey($settlement->performa_id)
            ->lockForUpdate()
            ->with('items')
            ->firstOrFail();

        $invoice->load('items');

        foreach ($invoice->items as $invLine) {
            if ($invLine->line_type === LineType::JEWELLERY_ITEM && $invLine->jewellery_item_id) {
                $jewellery = JewelleryItem::query()->lockForUpdate()->find($invLine->jewellery_item_id);
                if ($jewellery && $jewellery->status === JewelleryItemStatus::SOLD) {
                    $this->jewelleryItemService->restoreToOnMemoFromVoid($jewellery, $invoice, $actor);
                }

                $item = $performa->items->firstWhere('jewellery_item_id', $invLine->jewellery_item_id);
                if ($item) {
                    $item->quantity_settled = max(0, (int) $item->quantity_settled - (int) $invLine->quantity);
                    $item->save();
                }
            } elseif ($invLine->line_type === LineType::LOOSE_STONE && $invLine->stone_id) {
                $item = $performa->items->firstWhere('stone_id', $invLine->stone_id);
                if ($item && $invLine->weight_grams !== null) {
                    $item->weight_settled = max(0, round((float) $item->weight_settled - (float) $invLine->weight_grams, 3));
                    $item->save();
                } elseif ($item) {
                    $item->quantity_settled = max(0, (int) $item->quantity_settled - (int) $invLine->quantity);
                    $item->save();
                }
            }
        }

        $this->performaService->refreshStatus($performa);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{item: PerformaItem, quantity: int, price: string}>
     */
    private function validatedSelections(Performa $performa, array $data): array
    {
        $rows = $data['lines'] ?? [];
        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'lines' => 'Select at least one unpaid line to settle.',
            ]);
        }

        $items = $performa->items->keyBy('id');
        $selections = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['performa_item_id'] ?? 0);
            $item = $items->get($id);
            if (! $item) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'That line is not on this performa.',
                ]);
            }

            $outstanding = $item->outstandingAmount();
            if ($outstanding <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => "{$item->item_code_snapshot} has nothing unpaid.",
                ]);
            }

            // A weighed line is paid for in grams; pieces come whole.
            $qty = $item->isWeighed()
                ? round((float) ($row['quantity'] ?? 0), 3)
                : (int) ($row['quantity'] ?? 0);

            if (! $item->isWeighed() && (float) ($row['quantity'] ?? 0) !== (float) $qty) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => "{$item->item_code_snapshot} is settled in whole pieces.",
                ]);
            }
            if ($item->line_type === LineType::JEWELLERY_ITEM) {
                $qty = 1;
                if ($outstanding !== 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => 'A jewellery piece must be settled in full.',
                    ]);
                }
            }

            if ($qty <= 0 || $qty > $outstanding + 0.0005) {
                $unit = $item->isWeighed() ? ' g' : '';
                throw ValidationException::withMessages([
                    "lines.{$index}" => "{$item->item_code_snapshot} has only {$outstanding}{$unit} unpaid.",
                ]);
            }

            $selections[] = [
                'item' => $item,
                'quantity' => $qty,
                'gold_rate_applied' => $this->decimalOrNull($row['gold_rate_applied'] ?? null),
                'making_charge_type' => $row['making_charge_type'] ?? null,
                'making_charge_value' => $this->decimalOrNull($row['making_charge_value'] ?? null),
                'price_per_piece' => $this->decimalOrNull($row['price_per_piece'] ?? $row['price'] ?? null),
                'stones' => is_array($row['stones'] ?? null) ? $row['stones'] : [],
            ];
        }

        if ($selections === []) {
            throw ValidationException::withMessages([
                'lines' => 'Select at least one unpaid line to settle.',
            ]);
        }

        return $selections;
    }

    /**
     * Build calculator input the same way quotations do: gold × net weight
     * + making + jewellery stones. A leftover flat `price` becomes a fixed
     * making charge so older settlement payloads still work.
     *
     * @param  list<array<string, mixed>>  $selections
     * @return list<array<string, mixed>>
     */
    private function buildPricedLines(array $selections): array
    {
        $lines = [];

        foreach ($selections as $selection) {
            $item = $selection['item'];

            if ($item->line_type === LineType::JEWELLERY_ITEM) {
                $hasCalculator = $selection['gold_rate_applied'] !== null
                    || $selection['making_charge_type'] !== null
                    || $selection['making_charge_value'] !== null
                    || $selection['stones'] !== [];

                $lines[] = [
                    'line_type' => LineType::JEWELLERY_ITEM->value,
                    'jewellery_item_id' => $item->jewellery_item_id,
                    'item_code_snapshot' => $item->item_code_snapshot,
                    'name_snapshot' => $item->name_snapshot,
                    'category_snapshot' => $item->category_snapshot,
                    'metal_snapshot' => $item->metal_snapshot,
                    'purity_snapshot' => $item->purity_snapshot,
                    'gross_weight_snapshot' => $item->gross_weight_snapshot,
                    'net_weight_snapshot' => $item->net_weight_snapshot,
                    'quantity' => 1,
                    'gold_rate_applied' => $selection['gold_rate_applied'] ?? '0.00',
                    'making_charge_type' => $hasCalculator
                        ? ($selection['making_charge_type'] ?? MakingChargeType::NONE->value)
                        : MakingChargeType::FIXED->value,
                    'making_charge_value' => $hasCalculator
                        ? ($selection['making_charge_value'] ?? '0.00')
                        : ($selection['price_per_piece'] ?? '0.00'),
                    'stones' => $this->jewelleryStones($item, $selection['stones']),
                ];

                continue;
            }

            $price = $selection['price_per_piece'];
            if ($price === null) {
                throw ValidationException::withMessages([
                    'lines' => 'Enter a price for '.$item->item_code_snapshot.'.',
                ]);
            }

            $lines[] = [
                'line_type' => LineType::LOOSE_STONE->value,
                'stone_id' => $item->stone_id,
                'item_code_snapshot' => $item->item_code_snapshot,
                'name_snapshot' => $item->name_snapshot,
                'category_snapshot' => $item->category_snapshot,
                // A weighed line is sold by the gram: one line, grams carry the amount.
                'quantity' => $item->isWeighed() ? 1 : $selection['quantity'],
                'weight_grams' => $item->isWeighed() ? number_format((float) $selection['quantity'], 3, '.', '') : null,
                'price_per_piece' => $price,
                'stones' => [],
            ];
        }

        return $lines;
    }

    /**
     * Live jewellery-stone snapshots with operator prices. Identity never
     * comes from the browser.
     *
     * @param  list<array<string, mixed>>  $submitted
     * @return list<array<string, mixed>>
     */
    private function jewelleryStones(PerformaItem $item, array $submitted): array
    {
        $jewellery = $item->jewelleryItem;
        if (! $jewellery) {
            return [];
        }

        $jewellery->loadMissing('itemStones');
        $prices = [];
        foreach ($submitted as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['jewellery_item_stone_id'] ?? 0);
            if ($id > 0) {
                $prices[$id] = $row['price_per_piece'] ?? '0';
            }
        }

        return $jewellery->itemStones->map(fn ($stone): array => [
            'jewellery_item_stone_id' => $stone->id,
            'stone_name_snapshot' => $stone->stone_name_snapshot,
            'stone_type_snapshot' => $stone->stone_type_snapshot,
            'carat_snapshot' => (string) $stone->carat_snapshot,
            'shape_snapshot' => $stone->shape_snapshot,
            'color_snapshot' => $stone->color_snapshot,
            'clarity_snapshot' => $stone->clarity_snapshot,
            'quantity' => $stone->quantity,
            'price_per_piece' => $prices[$stone->id] ?? '0',
        ])->values()->all();
    }

    /**
     * @param  mixed  $value
     */
    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function writeInvoiceLine(Invoice $invoice, array $line, int $index): InvoiceItem
    {
        $type = LineType::from((string) $line['line_type']);

        $invoiceItem = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_type' => $type,
            'jewellery_item_id' => $type === LineType::JEWELLERY_ITEM ? ($line['jewellery_item_id'] ?? null) : null,
            'stone_id' => $type === LineType::LOOSE_STONE ? ($line['stone_id'] ?? null) : null,
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
            InvoiceItemStone::query()->create([
                'invoice_item_id' => $invoiceItem->id,
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

        return $invoiceItem;
    }

    /**
     * @param  array{item: PerformaItem, quantity: int, price: string}  $selection
     */
    private function applySettlementStock(array $selection, Invoice $invoice, Performa $performa, User $actor): void
    {
        $item = $selection['item'];

        if ($item->line_type === LineType::JEWELLERY_ITEM) {
            $jewellery = JewelleryItem::query()->lockForUpdate()->findOrFail($item->jewellery_item_id);
            if ($jewellery->status !== JewelleryItemStatus::ON_MEMO) {
                throw ValidationException::withMessages([
                    'lines' => $item->item_code_snapshot.' is no longer on memo.',
                ]);
            }
            $this->jewelleryItemService->markSold($jewellery, $invoice, $actor);

            return;
        }

        $stone = Stone::query()->lockForUpdate()->findOrFail($item->stone_id);
        $this->stoneStockService->recordUnchanged(
            $stone,
            StoneMovementType::SETTLED_FROM_CREDIT,
            $actor,
            $invoice,
            "Settled {$selection['quantity']}".($item->isWeighed() ? ' g' : '')." from performa {$performa->performa_number} on {$invoice->invoice_number}"
        );
    }
}
