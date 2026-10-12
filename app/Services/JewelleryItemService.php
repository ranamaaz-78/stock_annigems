<?php

namespace App\Services;

use App\Enums\JewelleryItemMovementType;
use App\Enums\JewelleryItemStatus;
use App\Models\JewelleryItem;
use App\Models\JewelleryItemMovement;
use App\Models\Purity;
use App\Models\Quotation;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * JewelleryItemService
 *
 * Create, update, status change, and soft-delete of individual jewellery
 * pieces. Stone stock, stone totals, codes, and QR tokens are never taken
 * from HTTP input. Typed metal_weight is persisted as net_weight.
 */
class JewelleryItemService
{
    public function __construct(
        private CodeSequenceService $codeSequenceService,
        private JewelleryStoneAllocationService $allocationService,
        private JewelleryImageService $imageService,
        private JewelleryWeightService $weightService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * Branch-scoped paginated list.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return JewelleryItem::query()
            ->with(['category', 'subCategory', 'metalType', 'purity', 'images'])
            ->withCount('itemStones')
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.$term.'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('item_code', 'like', $like)
                        ->orWhere('barcode', 'like', $like)
                        ->orWhere('name', 'like', $like);
                });
            })
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['sub_category_id'] ?? null, fn (Builder $q, $id) => $q->where('sub_category_id', $id))
            ->when($filters['metal_type_id'] ?? null, fn (Builder $q, $id) => $q->where('metal_type_id', $id))
            ->when($filters['purity_id'] ?? null, fn (Builder $q, $id) => $q->where('purity_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when(isset($filters['has_stones']) && $filters['has_stones'] === '1', fn (Builder $q) => $q->has('itemStones'))
            ->when(isset($filters['has_stones']) && $filters['has_stones'] === '0', fn (Builder $q) => $q->doesntHave('itemStones'))
            ->when($filters['weight_min'] ?? null, fn (Builder $q, $min) => $q->where('gross_weight', '>=', $min))
            ->when($filters['weight_max'] ?? null, fn (Builder $q, $max) => $q->where('gross_weight', '<=', $max))
            ->orderByDesc('id')
            ->paginate(per_page($perPage))
            ->withQueryString();
    }

    /**
     * Create a piece, deduct stones, store images, write movement + audit.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{uuid: string, quantity: int}>  $stones
     * @param  list<UploadedFile>  $images
     */
    public function create(array $data, array $stones, array $images, User $actor): JewelleryItem
    {
        $this->assertRelationships($data);

        return DB::transaction(function () use ($data, $stones, $images, $actor) {
            $code = $this->codeSequenceService->nextJewelleryCode();
            $emptyWeights = $this->weightService->derive([]);

            $item = JewelleryItem::query()->create([
                'branch_id' => $actor->branch_id,
                'item_code' => $code,
                'barcode' => setting('jewellery_barcode_enabled') ? $code : null,
                'qr_token' => (setting('jewellery_qr_enabled') && setting('public_product_page_enabled'))
                    ? Str::random(48)
                    : null,
                'category_id' => $data['category_id'],
                'sub_category_id' => $data['sub_category_id'] ?? null,
                'name' => trim((string) $data['name']),
                'metal_type_id' => $data['metal_type_id'],
                'purity_id' => $data['purity_id'],
                'gross_weight' => $data['gross_weight'],
                'stone_weight_carat' => $emptyWeights['stone_weight_carat'],
                'stone_weight_grams' => $emptyWeights['stone_weight_grams'],
                'net_weight' => $this->formatMetalWeight($data['metal_weight']),
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
            ]);

            $this->allocationService->allocateOnCreate($item, $stones, $actor);
            $this->imageService->sync($item, $images, [], null);

            $this->recordMovement($item, JewelleryItemMovementType::CREATED, null, $item->status, $actor);
            $this->auditLogService->log('jewellery.created', $item, null, $this->auditPayload($item), $actor);

            return $item->fresh(['category', 'metalType', 'purity', 'itemStones', 'images']);
        });
    }

    /**
     * Update attributes, apply stone deltas, sync images.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{uuid: string, quantity: int}>  $stones
     * @param  list<UploadedFile>  $images
     */
    public function update(JewelleryItem $item, array $data, array $stones, array $images, User $actor, ?int $primaryImageId = null): JewelleryItem
    {
        $this->assertRelationships($data);
        $old = $this->auditPayload($item);
        $oldStatus = $item->status;

        return DB::transaction(function () use ($item, $data, $stones, $images, $actor, $old, $oldStatus, $primaryImageId) {
            $item->fill([
                'category_id' => $data['category_id'],
                'sub_category_id' => $data['sub_category_id'] ?? null,
                'name' => trim((string) $data['name']),
                'metal_type_id' => $data['metal_type_id'],
                'purity_id' => $data['purity_id'],
                'gross_weight' => $data['gross_weight'],
                'net_weight' => $this->formatMetalWeight($data['metal_weight']),
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
            ]);
            $item->save();

            $stonesChanged = $this->allocationService->syncOnUpdate($item->fresh(), $stones, $actor);

            $this->imageService->sync($item->fresh(), $images, [], $primaryImageId);

            $item = $item->fresh();
            $newStatus = $item->status;

            if ($oldStatus !== $newStatus) {
                $this->recordMovement(
                    $item,
                    $this->statusMovementType($newStatus),
                    $oldStatus,
                    $newStatus,
                    $actor
                );
            }

            if ($stonesChanged) {
                $this->recordMovement($item, JewelleryItemMovementType::STONES_CHANGED, null, null, $actor, 'Stone allocation updated.');
            }

            $this->recordMovement($item, JewelleryItemMovementType::EDITED, null, null, $actor);
            $this->auditLogService->log('jewellery.updated', $item, $old, $this->auditPayload($item), $actor);

            return $item->fresh(['category', 'metalType', 'purity', 'itemStones', 'images']);
        });
    }

    /**
     * Mark item sold on invoice conversion. Internal stones are NOT touched —
     * they were deducted at manufacture (Module 4 USED_IN_ITEM).
     */
    public function markSold(JewelleryItem $item, \App\Models\Invoice $invoice, User $actor): JewelleryItem
    {
        if ($item->status === JewelleryItemStatus::SOLD) {
            return $item;
        }

        $old = $item->status;
        $item->status = JewelleryItemStatus::SOLD;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::SOLD,
            $old,
            JewelleryItemStatus::SOLD,
            $actor,
            'Sold on invoice '.$invoice->invoice_number
        );

        return $item->fresh();
    }

    /**
     * Hold a piece on a draft quotation so POS cannot sell it to someone else.
     * Convert later calls markSold(); cancel calls restoreFromQuotation().
     */
    public function markReserved(JewelleryItem $item, Quotation $quotation, User $actor): JewelleryItem
    {
        if ($item->status !== JewelleryItemStatus::AVAILABLE) {
            throw ValidationException::withMessages([
                'lines' => $item->item_code.' is not available.',
            ]);
        }

        $old = $item->status;
        $item->status = JewelleryItemStatus::RESERVED;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::RESERVED,
            $old,
            JewelleryItemStatus::RESERVED,
            $actor,
            'Reserved on quotation '.$quotation->quotation_number
        );

        return $item->fresh();
    }

    /**
     * Put a quoted piece back on the shelf when the draft is cancelled or
     * the line is removed on edit.
     */
    public function restoreFromQuotation(JewelleryItem $item, Quotation $quotation, User $actor): JewelleryItem
    {
        if ($item->status !== JewelleryItemStatus::RESERVED) {
            return $item;
        }

        $old = $item->status;
        $item->status = JewelleryItemStatus::AVAILABLE;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::STATUS_CHANGED,
            $old,
            JewelleryItemStatus::AVAILABLE,
            $actor,
            'Released from quotation '.$quotation->quotation_number
        );

        return $item->fresh();
    }

    /**
     * Issue a piece on a performa. Must be available; becomes on_memo so it
     * cannot be quoted or sold to anyone else while the customer holds it.
     */
    public function markIssuedOnCredit(JewelleryItem $item, string $performaNumber, User $actor): JewelleryItem
    {
        if ($item->status !== JewelleryItemStatus::AVAILABLE) {
            throw ValidationException::withMessages([
                'lines' => $item->item_code.' is not available.',
            ]);
        }

        $old = $item->status;
        $item->status = JewelleryItemStatus::ON_MEMO;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::ISSUED_ON_CREDIT,
            $old,
            JewelleryItemStatus::ON_MEMO,
            $actor,
            'Issued on performa '.$performaNumber
        );

        return $item->fresh();
    }

    /**
     * Return an unpaid on-memo piece to the shelf.
     */
    public function restoreFromCredit(JewelleryItem $item, string $performaNumber, User $actor): JewelleryItem
    {
        if ($item->status !== JewelleryItemStatus::ON_MEMO) {
            return $item;
        }

        $old = $item->status;
        $item->status = JewelleryItemStatus::AVAILABLE;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::RETURNED_FROM_CREDIT,
            $old,
            JewelleryItemStatus::AVAILABLE,
            $actor,
            'Returned from performa '.$performaNumber
        );

        return $item->fresh();
    }

    /**
     * After a settlement invoice is voided, the piece is still with the
     * customer — put it back on memo, not on the shelf.
     */
    public function restoreToOnMemoFromVoid(JewelleryItem $item, \App\Models\Invoice $invoice, User $actor): JewelleryItem
    {
        $old = $item->status;
        $item->status = JewelleryItemStatus::ON_MEMO;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::SALE_VOIDED,
            $old,
            JewelleryItemStatus::ON_MEMO,
            $actor,
            'Settlement voided: '.$invoice->invoice_number
        );

        return $item->fresh();
    }

    /**
     * Hand a piece to a client on a delivery note. Must be available; it
     * leaves the shelf now and becomes sold only if the note is invoiced.
     */
    public function markDelivered(JewelleryItem $item, string $deliveryNoteNumber, User $actor): JewelleryItem
    {
        if ($item->status !== JewelleryItemStatus::AVAILABLE) {
            throw ValidationException::withMessages([
                'lines' => $item->item_code.' is not available.',
            ]);
        }

        $old = $item->status;
        $item->status = JewelleryItemStatus::DELIVERED;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::DELIVERED,
            $old,
            JewelleryItemStatus::DELIVERED,
            $actor,
            'Delivered on delivery note '.$deliveryNoteNumber
        );

        return $item->fresh();
    }

    /**
     * A cancelled delivery note puts the piece back on the shelf.
     */
    public function restoreFromDelivery(JewelleryItem $item, string $deliveryNoteNumber, User $actor): JewelleryItem
    {
        if ($item->status !== JewelleryItemStatus::DELIVERED) {
            return $item;
        }

        $old = $item->status;
        $item->status = JewelleryItemStatus::AVAILABLE;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::RETURNED_FROM_DELIVERY,
            $old,
            JewelleryItemStatus::AVAILABLE,
            $actor,
            'Returned from delivery note '.$deliveryNoteNumber
        );

        return $item->fresh();
    }

    /**
     * After an invoice made from a delivery note is voided, the piece is
     * still with the client — back to delivered, not to the shelf.
     */
    public function restoreToDeliveredFromVoid(JewelleryItem $item, \App\Models\Invoice $invoice, User $actor): JewelleryItem
    {
        if ($item->status !== JewelleryItemStatus::SOLD) {
            return $item;
        }

        $old = $item->status;
        $item->status = JewelleryItemStatus::DELIVERED;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::SALE_VOIDED,
            $old,
            JewelleryItemStatus::DELIVERED,
            $actor,
            'Invoice voided: '.$invoice->invoice_number
        );

        return $item->fresh();
    }

    /**
     * Restore item to available when an invoice is voided.
     */
    public function restoreFromVoid(JewelleryItem $item, \App\Models\Invoice $invoice, User $actor): JewelleryItem
    {
        $old = $item->status;
        $item->status = JewelleryItemStatus::AVAILABLE;
        $item->save();

        $this->recordMovement(
            $item,
            JewelleryItemMovementType::SALE_VOIDED,
            $old,
            JewelleryItemStatus::AVAILABLE,
            $actor,
            'Invoice voided: '.$invoice->invoice_number
        );

        return $item->fresh();
    }

    /**
     * Change status only (jewellery.status permission).
     */
    public function changeStatus(JewelleryItem $item, JewelleryItemStatus $status, User $actor, ?string $notes = null): JewelleryItem
    {
        if (! in_array($status, JewelleryItemStatus::usableInThisModule(), true)) {
            throw ValidationException::withMessages([
                'status' => 'That status cannot be set from jewellery management.',
            ]);
        }

        $old = $item->status;
        if ($old === $status) {
            return $item;
        }

        return DB::transaction(function () use ($item, $status, $actor, $old, $notes) {
            $payload = $this->auditPayload($item);
            $item->status = $status;
            $item->save();
            $this->recordMovement($item, $this->statusMovementType($status), $old, $status, $actor, $notes);
            $this->auditLogService->log('jewellery.status_changed', $item, $payload, $this->auditPayload($item), $actor);

            return $item->fresh();
        });
    }

    /**
     * Soft-delete and return allocated stones.
     *
     * @return array{item: JewelleryItem, stones_returned: int}
     */
    public function delete(JewelleryItem $item, User $actor): array
    {
        $old = $this->auditPayload($item);

        return DB::transaction(function () use ($item, $actor, $old) {
            $returned = $this->allocationService->returnAll($item, $actor);
            $item->delete();
            $this->auditLogService->log('jewellery.deleted', $item, $old, null, $actor);

            return ['item' => $item, 'stones_returned' => $returned];
        });
    }

    /**
     * Sub-category must belong to category; purity must belong to metal.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRelationships(array $data): void
    {
        if (! empty($data['sub_category_id'])) {
            $sub = SubCategory::query()->find($data['sub_category_id']);
            if (! $sub || (int) $sub->category_id !== (int) $data['category_id']) {
                throw ValidationException::withMessages([
                    'sub_category_id' => 'That sub-category does not belong to the selected category.',
                ]);
            }
        }

        $purity = Purity::query()->find($data['purity_id']);
        if (! $purity || (int) $purity->metal_type_id !== (int) $data['metal_type_id']) {
            throw ValidationException::withMessages([
                'purity_id' => 'That purity does not belong to the selected metal.',
            ]);
        }
    }

    /**
     * Map a status to a more specific movement when possible.
     */
    private function statusMovementType(JewelleryItemStatus $status): JewelleryItemMovementType
    {
        return match ($status) {
            JewelleryItemStatus::DAMAGED => JewelleryItemMovementType::DAMAGED,
            JewelleryItemStatus::LOST => JewelleryItemMovementType::LOST,
            JewelleryItemStatus::UNDER_REPAIR => JewelleryItemMovementType::REPAIR_IN,
            JewelleryItemStatus::RESERVED => JewelleryItemMovementType::RESERVED,
            JewelleryItemStatus::AVAILABLE => JewelleryItemMovementType::STATUS_CHANGED,
            default => JewelleryItemMovementType::STATUS_CHANGED,
        };
    }

    /**
     * Append a timeline row (attribution via performed_by trait).
     */
    private function recordMovement(
        JewelleryItem $item,
        JewelleryItemMovementType $type,
        ?JewelleryItemStatus $from,
        ?JewelleryItemStatus $to,
        User $actor,
        ?string $notes = null
    ): void {
        JewelleryItemMovement::query()->create([
            'branch_id' => $item->branch_id,
            'jewellery_item_id' => $item->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
        ]);
    }

    /**
     * Persist typed metal grams as the sale/net weight (3 decimal places).
     */
    private function formatMetalWeight(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(JewelleryItem $item): array
    {
        return $item->only([
            'item_code', 'name', 'status', 'gross_weight', 'net_weight',
            'category_id', 'metal_type_id', 'purity_id',
        ]);
    }
}
