<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\LineType;
use App\Enums\MakingChargeType;
use App\Enums\QuotationStatus;
use App\Enums\TaxType;
use App\Models\Customer;
use App\Models\JewelleryItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationItemStone;
use App\Models\Stone;
use App\Models\User;
use App\Support\Settings\DocumentSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QuotationService
 *
 * Branch-scoped quotation CRUD, line management, and authoritative recalc on
 * save. Overwrites previous values — no version history. Save also holds
 * stock (via QuotationStockService) so a quote removes the piece/qty from POS.
 */
class QuotationService
{
    public function __construct(
        private CodeSequenceService $codeSequence,
        private PricingCalculationService $pricing,
        private AuditLogService $auditLog,
        private QuotationDraftService $draftService,
        private SalesLookupService $lookupService,
        private CustomerService $customerService,
        private QuotationStockService $quotationStock,
    ) {}

    /**
     * Paginated quotation list for the current branch.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Quotation::query()
            ->with(['customer', 'creator'])
            ->withCount('items')
            ->orderByDesc('quotation_date')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('quotation_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('quotation_date', '<=', $filters['to']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($term): void {
                $q->where('quotation_number', 'like', "%{$term}%")
                    ->orWhere('walkin_name', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
            });
        }

        return $query->paginate(per_page())->withQueryString();
    }

    /**
     * Load quotation with nested lines and stones for edit/show/print.
     */
    public function loadDetail(Quotation $quotation): Quotation
    {
        return $quotation->load([
            'customer',
            'branch',
            'creator',
            'updater',
            'items.itemStones',
            'convertedInvoice',
        ]);
    }

    /**
     * Convert persisted quotation to recalculable payload array.
     *
     * @return array<string, mixed>
     */
    public function toPayload(Quotation $quotation): array
    {
        $quotation = $this->loadDetail($quotation);

        $lines = $quotation->items->map(function (QuotationItem $item): array {
            $line = [
                'id' => $item->id,
                'line_type' => $item->line_type->value,
                'jewellery_item_id' => $item->jewellery_item_id,
                'stone_id' => $item->stone_id,
                'item_code_snapshot' => $item->item_code_snapshot,
                'name_snapshot' => $item->name_snapshot,
                'category_snapshot' => $item->category_snapshot,
                'metal_snapshot' => $item->metal_snapshot,
                'purity_snapshot' => $item->purity_snapshot,
                'purity_id_snapshot' => $item->purity_id_snapshot,
                'gross_weight_snapshot' => (string) $item->gross_weight_snapshot,
                'net_weight_snapshot' => (string) $item->net_weight_snapshot,
                'quantity' => $item->quantity,
                'gold_rate_applied' => (string) $item->gold_rate_applied,
                'making_charge_type' => $item->making_charge_type->value,
                'making_charge_value' => (string) $item->making_charge_value,
                'stones' => [],
            ];

            if ($item->line_type === LineType::LOOSE_STONE) {
                $line['price_per_piece'] = $item->quantity > 0
                    ? number_format((float) $item->stone_value / $item->quantity, 2, '.', '')
                    : '0.00';
            } else {
                $line['stones'] = $item->itemStones->map(fn (QuotationItemStone $s): array => [
                    'id' => $s->id,
                    'jewellery_item_stone_id' => $s->jewellery_item_stone_id,
                    'stone_name_snapshot' => $s->stone_name_snapshot,
                    'stone_type_snapshot' => $s->stone_type_snapshot,
                    'carat_snapshot' => (string) $s->carat_snapshot,
                    'shape_snapshot' => $s->shape_snapshot,
                    'color_snapshot' => $s->color_snapshot,
                    'clarity_snapshot' => $s->clarity_snapshot,
                    'quantity' => $s->quantity,
                    'price_per_piece' => (string) $s->price_per_piece,
                ])->values()->all();
            }

            return $line;
        })->values()->all();

        return [
            'quotation_id' => $quotation->id,
            'uuid' => $quotation->uuid,
            'quotation_number' => $quotation->quotation_number,
            'customer_id' => $quotation->customer_id,
            'customer_uuid' => $quotation->customer?->uuid,
            'customer_name' => $quotation->customer?->name,
            'customer_phone' => $quotation->customer?->phone,
            'customer_code' => $quotation->customer?->customer_code,
            'walkin_name' => $quotation->walkin_name,
            'walkin_phone' => $quotation->walkin_phone,
            'quotation_date' => $quotation->quotation_date->toDateString(),
            'default_gold_rate' => $quotation->default_gold_rate,
            'default_stone_price' => $quotation->default_stone_price,
            'default_making_charge_type' => $quotation->default_making_charge_type?->value,
            'default_making_charge_value' => $quotation->default_making_charge_value,
            'discount_type' => $quotation->discount_type->value,
            'discount_value' => (string) $quotation->discount_value,
            'tax_type' => $quotation->tax_type->value,
            'tax_rate' => (string) $quotation->tax_rate,
            'tax_name' => $quotation->tax_name,
            'recargo_rate' => (string) $quotation->recargo_rate,
            'recargo_name' => $quotation->recargo_name,
            'notes' => $quotation->notes,
            'lines' => $lines,
        ];
    }

    /**
     * Hydrate, price, and gate a workspace payload without writing a quotation.
     *
     * Shared by draft save and direct invoice issue so both buttons on the
     * workspace run the same tax overlay, snapshot rebuild, and discount cap.
     * Stock locks stay out of this method — callers take them inside their
     * own transaction via assertLinesAvailable().
     *
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, lines: list<array<string, mixed>>, document: array<string, mixed>}
     */
    public function prepareWorkspace(array $data, User $actor): array
    {
        // Tax type/rate come from SuperAdmin settings, not the form. A
        // crafted payload cannot pick a different GST.
        $data = DocumentSettings::applyToPayload($data, $actor);
        $this->assertDiscountPermission($data, $actor);

        // Descriptive fields (names, purity, weights, stone carats) are rebuilt
        // from the live records before any maths runs — the browser only gets to
        // choose rates and quantities.
        $lines = $this->lookupService->hydrateLines($data['lines'] ?? [], $actor);

        $recalculated = $this->pricing->recalculate(
            $lines,
            [
                'discount_type' => $data['discount_type'] ?? DiscountType::NONE->value,
                'discount_value' => $data['discount_value'] ?? 0,
                'tax_type' => $data['tax_type'],
                'tax_rate' => $data['tax_rate'],
                'tax_name' => $data['tax_name'] ?? null,
                'recargo_rate' => $data['recargo_rate'] ?? 0,
                'recargo_name' => $data['recargo_name'] ?? null,
            ]
        );

        $this->assertDiscountCeiling($recalculated['document'], $actor);

        return [
            'data' => $data,
            'lines' => $recalculated['lines'],
            'document' => $recalculated['document'],
        ];
    }

    /**
     * Re-check stock with row locks. Must run inside the caller's transaction.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function assertLinesAvailable(array $lines, User $actor, ?Quotation $existing = null): void
    {
        $this->validateLinesAvailability($lines, $actor, $existing);
    }

    /**
     * Create or update a draft quotation from validated payload.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, User $actor, ?Quotation $existing = null): Quotation
    {
        $this->assertEditable($existing);

        $prepared = $this->prepareWorkspace($data, $actor);
        $data = $prepared['data'];
        $recalculated = [
            'lines' => $prepared['lines'],
            'document' => $prepared['document'],
        ];

        return DB::transaction(function () use ($data, $actor, $existing, $recalculated): Quotation {
            $isNew = $existing === null;

            // Edit: put the previous hold back first so the new lines are
            // checked against live stock (including qty this quote already cut).
            if (! $isNew) {
                $existing->load('items');
                $this->quotationStock->release($existing, $actor);
            }

            $this->assertLinesAvailable($recalculated['lines'], $actor, $existing);

            $old = $existing ? $this->auditSnapshot($existing) : null;

            $customerId = $this->resolveCustomerId($data, $actor);

            if ($isNew) {
                // Built unsaved, then filled and saved once below. Persisting the
                // identity columns on their own would emit an INSERT without
                // quotation_date, which MySQL rejects (no default on that column).
                $existing = new Quotation([
                    'branch_id' => $actor->branch_id,
                    'quotation_number' => $this->codeSequence->nextQuotationNumber(),
                    'status' => QuotationStatus::DRAFT,
                ]);
            }

            $existing->fill([
                'customer_id' => $customerId,
                // Walk-in name/phone become a real customer row; keep the
                // columns null so new quotations never store a shadow identity.
                'walkin_name' => null,
                'walkin_phone' => null,
                'quotation_date' => $data['quotation_date'] ?? now()->toDateString(),
                'default_gold_rate' => $data['default_gold_rate'] ?? null,
                'default_stone_price' => $data['default_stone_price'] ?? null,
                'default_making_charge_type' => ! empty($data['default_making_charge_type'])
                    ? MakingChargeType::from($data['default_making_charge_type'])
                    : null,
                'default_making_charge_value' => $data['default_making_charge_value'] ?? null,
                'subtotal' => $recalculated['document']['subtotal'],
                'discount_type' => DiscountType::from($recalculated['document']['discount_type']),
                'discount_value' => $recalculated['document']['discount_value'],
                'discount_amount' => $recalculated['document']['discount_amount'],
                'tax_type' => TaxType::from($recalculated['document']['tax_type']),
                'tax_rate' => $recalculated['document']['tax_rate'],
                'tax_amount' => $recalculated['document']['tax_amount'],
                'tax_name' => $recalculated['document']['tax_name'],
                'recargo_name' => $recalculated['document']['recargo_name'],
                'recargo_rate' => $recalculated['document']['recargo_rate'],
                'recargo_amount' => $recalculated['document']['recargo_amount'],
                'grand_total' => $recalculated['document']['grand_total'],
                'notes' => $data['notes'] ?? null,
            ]);
            $existing->save();

            $this->syncLines($existing, $recalculated['lines']);
            $this->quotationStock->reserve($existing, $recalculated['lines'], $actor);

            $this->auditLog->log(
                $isNew ? 'quotation.created' : 'quotation.updated',
                $existing,
                $old,
                $this->auditSnapshot($existing->fresh()),
                $actor
            );

            $this->draftService->discard($actor);

            return $this->loadDetail($existing->fresh());
        });
    }

    /**
     * Cancel a draft quotation.
     */
    public function cancel(Quotation $quotation, User $actor): Quotation
    {
        $this->assertEditable($quotation);

        return DB::transaction(function () use ($quotation, $actor): Quotation {
            $old = $this->auditSnapshot($quotation);
            $this->quotationStock->release($quotation, $actor);
            $quotation->update(['status' => QuotationStatus::CANCELLED]);
            $this->auditLog->log('quotation.cancelled', $quotation, $old, $this->auditSnapshot($quotation->fresh()), $actor);

            return $quotation->fresh();
        });
    }

    /**
     * Replace all line rows from recalculated payload.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(Quotation $quotation, array $lines): void
    {
        $quotation->items()->each(function (QuotationItem $item): void {
            $item->itemStones()->delete();
        });
        $quotation->items()->delete();

        foreach ($lines as $index => $line) {
            $item = $quotation->items()->create([
                'line_type' => $line['line_type'],
                'jewellery_item_id' => $line['line_type'] === LineType::JEWELLERY_ITEM->value ? ($line['jewellery_item_id'] ?? null) : null,
                'stone_id' => $line['line_type'] === LineType::LOOSE_STONE->value ? ($line['stone_id'] ?? null) : null,
                'item_code_snapshot' => $line['item_code_snapshot'] ?? '',
                'name_snapshot' => $line['name_snapshot'] ?? '',
                'category_snapshot' => $line['category_snapshot'] ?? null,
                'metal_snapshot' => $line['metal_snapshot'] ?? null,
                'purity_snapshot' => $line['purity_snapshot'] ?? null,
                'purity_id_snapshot' => $line['purity_id_snapshot'] ?? null,
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
                $item->itemStones()->create([
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
        }
    }

    /**
     * Validate stock and duplicate jewellery rules before save.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function validateLinesAvailability(array $lines, User $actor, ?Quotation $existing): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Add at least one line to the quotation.')]);
        }

        $jewelleryIds = [];

        // Grams wanted of each weighed lot across the whole ticket, so two lines of
        // the same lot cannot each pass the check and together overdraw it.
        $gramsWanted = [];
        foreach ($lines as $row) {
            if (LineType::from((string) $row['line_type']) === LineType::LOOSE_STONE && (float) ($row['weight_grams'] ?? 0) > 0) {
                $key = (int) ($row['stone_id'] ?? 0);
                $gramsWanted[$key] = round(($gramsWanted[$key] ?? 0) + (float) $row['weight_grams'], 3);
            }
        }

        foreach ($lines as $index => $line) {
            $lineNum = $index + 1;
            $type = LineType::from((string) $line['line_type']);

            if ($type === LineType::JEWELLERY_ITEM) {
                $itemId = (int) ($line['jewellery_item_id'] ?? 0);
                if (isset($jewelleryIds[$itemId])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => __(':code is already on this quotation.', ['code' => $line['item_code_snapshot']]),
                    ]);
                }
                $jewelleryIds[$itemId] = true;

                $item = JewelleryItem::query()
                    ->where('branch_id', $actor->branch_id)
                    ->whereKey($itemId)
                    ->lockForUpdate()
                    ->first();

                if (! $item || $item->status->value !== 'available') {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => __('Line :line: :code is not available.', ['line' => $lineNum, 'code' => $line['item_code_snapshot']]),
                    ]);
                }
            } else {
                $stoneId = (int) ($line['stone_id'] ?? 0);
                $qty = (int) ($line['quantity'] ?? 1);
                $stone = Stone::query()
                    ->where('branch_id', $actor->branch_id)
                    ->whereKey($stoneId)
                    ->lockForUpdate()
                    ->first();

                if ($stone && $stone->isWeighed()) {
                    $wanted = $gramsWanted[$stoneId] ?? 0.0;
                    $available = round((float) $stone->weight_grams, 3);

                    if ($wanted <= 0 || $available + 0.0005 < $wanted) {
                        throw ValidationException::withMessages([
                            "lines.{$index}" => __('Line :line: :code has only :available g in stock, :wanted g requested.', ['line' => $lineNum, 'code' => $line['item_code_snapshot'], 'available' => $available, 'wanted' => $wanted]),
                        ]);
                    }

                    continue;
                }

                if (! $stone || (int) $stone->quantity < $qty) {
                    $available = $stone ? (int) $stone->quantity : 0;
                    throw ValidationException::withMessages([
                        "lines.{$index}" => __('Line :line: :code has only :available in stock, :qty requested.', ['line' => $lineNum, 'code' => $line['item_code_snapshot'], 'available' => $available, 'qty' => $qty]),
                    ]);
                }
            }
        }
    }

    /**
     * Enforce invoices.discount permission server-side.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertDiscountPermission(array $data, User $actor): void
    {
        $discountType = DiscountType::tryFrom((string) ($data['discount_type'] ?? 'none'));
        $taxType = TaxType::tryFrom((string) ($data['tax_type'] ?? 'none'));

        if (! setting('discount_enabled')) {
            if ($discountType && $discountType !== DiscountType::NONE) {
                throw ValidationException::withMessages([
                    'discount_type' => __('Discounts are turned off. Remove the discount to save this quotation.'),
                ]);
            }
        } else {
            if ($discountType && $discountType !== DiscountType::NONE && ! $actor->hasPermission('invoices.discount')) {
                throw ValidationException::withMessages([
                    'discount_type' => __('You do not have permission to apply discounts.'),
                ]);
            }

            $this->assertPercentageCeiling((float) ($data['discount_value'] ?? 0));
        }

        if (! setting('tax_enabled')) {
            if ($taxType && $taxType !== TaxType::NONE && (float) ($data['tax_rate'] ?? 0) > 0) {
                throw ValidationException::withMessages([
                    'tax_type' => __('Tax is turned off. Remove the tax to save this quotation.'),
                ]);
            }
        }
    }

    /**
     * Cap a percentage (or a fixed amount as a percent of subtotal).
     *
     * @param  array<string, mixed>  $document
     */
    private function assertDiscountCeiling(array $document, User $actor): void
    {
        $max = DocumentSettings::maxDiscountPercentage($actor);
        $type = DiscountType::tryFrom((string) ($document['discount_type'] ?? 'none'));

        if ($type === DiscountType::PERCENTAGE) {
            $this->assertPercentageCeiling((float) ($document['discount_value'] ?? 0));

            return;
        }

        if ($type === DiscountType::FIXED) {
            $subtotal = (float) ($document['subtotal'] ?? 0);
            $amount = (float) ($document['discount_amount'] ?? 0);

            if ($subtotal > 0 && ($amount / $subtotal) * 100 > $max + 0.009) {
                throw ValidationException::withMessages([
                    'discount_value' => __('Discount cannot exceed :max%.', ['max' => $max]),
                ]);
            }
        }
    }

    /**
     * Named ceiling for a percentage discount.
     */
    private function assertPercentageCeiling(float $value): void
    {
        $max = DocumentSettings::maxDiscountPercentage();

        if ($value > $max) {
            throw ValidationException::withMessages([
                'discount_value' => __('Discount cannot exceed :max%.', ['max' => $max]),
            ]);
        }
    }

    /**
     * Prefer an existing pick (uuid/id). Otherwise register or reuse the
     * walk-in name+phone as a real customer and return that id.
     *
     * Public so InvoiceConversionService can attach the same customer when
     * issuing an invoice straight from the workspace.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolveCustomerId(array $data, User $actor): ?int
    {
        if (! empty($data['customer_uuid'])) {
            $existing = Customer::query()->where('uuid', $data['customer_uuid'])->first();
            if ($existing) {
                // The POS sends the fields back when the cashier has them open for editing.
                if (! empty($data['update_customer'])) {
                    $this->customerService->syncFromTicket($existing, $data, $actor);
                }

                return (int) $existing->id;
            }
        }

        if (! empty($data['customer_id'])) {
            return (int) $data['customer_id'];
        }

        $name = trim((string) ($data['walkin_name'] ?? ''));
        $phone = trim((string) ($data['walkin_phone'] ?? ''));
        $email = trim((string) ($data['walkin_email'] ?? ''));
        $nie = trim((string) ($data['walkin_nie'] ?? ''));
        $address = trim((string) ($data['walkin_address'] ?? ''));
        if ($name !== '' && $phone !== '') {
            return $this->customerService->findOrCreateByPhone(
                $name,
                $phone,
                $actor,
                $email !== '' ? $email : null,
                $nie !== '' ? $nie : null,
                $address !== '' ? $address : null
            )->id;
        }

        return null;
    }

    /**
     * Only draft quotations may be edited or cancelled.
     */
    private function assertEditable(?Quotation $quotation): void
    {
        if ($quotation && $quotation->status !== QuotationStatus::DRAFT) {
            throw ValidationException::withMessages([
                'quotation' => __('This quotation can no longer be edited.'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Quotation $quotation): array
    {
        return [
            'quotation_number' => $quotation->quotation_number,
            'status' => $quotation->status->value,
            'grand_total' => (string) $quotation->grand_total,
        ];
    }
}
