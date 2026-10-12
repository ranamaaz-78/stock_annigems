<?php

namespace App\Services;

use App\Enums\LineType;
use App\Enums\StoneMovementType;
use App\Models\JewelleryItem;
use App\Models\Quotation;
use App\Models\Stone;
use App\Models\StoneStockMovement;
use App\Models\User;

/**
 * QuotationStockService
 *
 * Holds inventory the moment a quotation is saved — jewellery becomes
 * reserved, loose-stone quantity is cut — so POS cannot sell the same
 * piece or packet on another ticket. Convert turns the hold into a sale
 * without cutting again. Cancel (or a draft edit) puts the stock back.
 *
 * Jewellery stones that sit inside a piece are never touched: they left
 * the drawer at manufacture (USED_IN_ITEM).
 */
class QuotationStockService
{
    public function __construct(
        private JewelleryItemService $jewelleryItemService,
        private StoneStockService $stoneStockService,
    ) {}

    /**
     * Lock each line: jewellery → reserved, loose stone → quantity out.
     *
     * @param  list<array<string, mixed>>  $lines  Hydrated, already-validated lines
     */
    public function reserve(Quotation $quotation, array $lines, User $actor): void
    {
        foreach ($lines as $line) {
            $type = LineType::from((string) $line['line_type']);

            if ($type === LineType::JEWELLERY_ITEM) {
                $item = JewelleryItem::query()->lockForUpdate()->findOrFail($line['jewellery_item_id']);
                $this->jewelleryItemService->markReserved($item, $quotation, $actor);

                continue;
            }

            $stone = Stone::query()->lockForUpdate()->findOrFail($line['stone_id']);
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $this->stoneStockService->apply(
                $stone,
                StoneMovementType::RESERVED_ON_QUOTATION,
                -$qty,
                $actor,
                $quotation,
                'Reserved on quotation '.$quotation->quotation_number
            );
        }
    }

    /**
     * Undo an active hold. Safe to call when nothing was reserved (legacy
     * drafts created before quotations cut stock) — jewellery that is not
     * reserved and stones with a zero net hold are skipped.
     */
    public function release(Quotation $quotation, User $actor): void
    {
        $quotation->loadMissing('items');

        foreach ($quotation->items as $line) {
            if ($line->line_type === LineType::JEWELLERY_ITEM && $line->jewellery_item_id) {
                $item = JewelleryItem::query()->lockForUpdate()->find($line->jewellery_item_id);
                if ($item) {
                    $this->jewelleryItemService->restoreFromQuotation($item, $quotation, $actor);
                }

                continue;
            }

            if ($line->line_type !== LineType::LOOSE_STONE || ! $line->stone_id) {
                continue;
            }

            $qty = $this->reservedStoneQuantity($quotation, (int) $line->stone_id);
            if ($qty < 1) {
                continue;
            }

            $stone = Stone::query()->lockForUpdate()->find($line->stone_id);
            if (! $stone) {
                continue;
            }

            $this->stoneStockService->apply(
                $stone,
                StoneMovementType::RELEASED_FROM_QUOTATION,
                $qty,
                $actor,
                $quotation,
                'Released from quotation '.$quotation->quotation_number
            );
        }
    }

    /**
     * How many of this stone are still held by this quotation.
     *
     * Net of reserved_on_quotation (negative) and released_from_quotation
     * (positive) movements pointed at this quote. Zero for legacy drafts.
     */
    public function reservedStoneQuantity(Quotation $quotation, int $stoneId): int
    {
        $net = (int) StoneStockMovement::query()
            ->where('reference_type', $quotation::class)
            ->where('reference_id', $quotation->id)
            ->where('stone_id', $stoneId)
            ->whereIn('type', [
                StoneMovementType::RESERVED_ON_QUOTATION,
                StoneMovementType::RELEASED_FROM_QUOTATION,
            ])
            ->sum('quantity_change');

        return max(0, -$net);
    }
}
