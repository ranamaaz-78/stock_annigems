<?php

namespace App\Services;

use App\Enums\StonePricingMode;
use App\Enums\StoneMovementType;
use App\Models\Stone;
use App\Models\StoneStockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;

/**
 * StoneStockService
 *
 * THE ONLY class permitted to write stones.quantity. Every call also inserts
 * a stone_stock_movements row with before/after values in the same
 * transaction the caller opened.
 *
 * Controllers, jobs, and other services must never call
 * $stone->update(['quantity' => …]) or $stone->quantity = n.
 */
class StoneStockService
{
    /**
     * Apply a signed quantity change and record the ledger row.
     *
     * @param  Model|null  $reference  Intake today; later an item, sale, or adjustment
     */
    public function apply(
        Stone $stone,
        StoneMovementType $type,
        int $quantityChange,
        User $actor,
        ?Model $reference = null,
        ?string $notes = null
    ): StoneStockMovement {
        if ($quantityChange === 0) {
            throw new InvalidArgumentException('Quantity change cannot be zero.');
        }

        $before = (int) $stone->quantity;
        $after = $before + $quantityChange;

        if ($after < 0) {
            throw new RuntimeException(
                "Insufficient stock for {$stone->stone_code}: on hand {$before}, change {$quantityChange}."
            );
        }

        $stone->authorizeQuantityWrite();
        $stone->quantity = $after;
        $stone->syncStockStatus();
        $stone->save();

        return StoneStockMovement::query()->create([
            'branch_id' => $stone->branch_id,
            'stone_id' => $stone->id,
            'type' => $type,
            'quantity_change' => $quantityChange,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'notes' => $notes,
        ]);
    }

    /**
     * Apply a signed weight change to a per-gram lot and record the ledger row.
     *
     * The weight twin of apply(): grams in `stones.weight_grams` move only
     * here, and the movement keeps before and after so the chain can be
     * audited. The piece columns of the row stay equal because pieces did
     * not move.
     *
     * @param  float|string  $gramsChange  Signed grams, three decimals
     */
    public function applyWeight(
        Stone $stone,
        StoneMovementType $type,
        float|string $gramsChange,
        User $actor,
        ?Model $reference = null,
        ?string $notes = null
    ): StoneStockMovement {
        $change = round((float) $gramsChange, 3);

        if ($change === 0.0) {
            throw new InvalidArgumentException('Weight change cannot be zero.');
        }

        $before = round((float) ($stone->weight_grams ?? 0), 3);
        $after = round($before + $change, 3);

        if ($after < 0) {
            throw new RuntimeException(
                "Insufficient weight for {$stone->stone_code}: on hand {$before} {$stone->weightUnit()}, change {$change} {$stone->weightUnit()}."
            );
        }

        $stone->weight_grams = number_format($after, 3, '.', '');
        // A per-carat lot's carats are its stock, so the carat column follows it.
        if ($stone->pricing_mode === StonePricingMode::PER_CARAT) {
            $stone->carat = number_format($after, 3, '.', '');
        }
        $stone->syncStockStatus();
        $stone->save();

        $pieces = (int) $stone->quantity;

        return StoneStockMovement::query()->create([
            'branch_id' => $stone->branch_id,
            'stone_id' => $stone->id,
            'type' => $type,
            'quantity_change' => 0,
            'quantity_before' => $pieces,
            'quantity_after' => $pieces,
            'weight_change' => number_format($change, 3, '.', ''),
            'weight_before' => number_format($before, 3, '.', ''),
            'weight_after' => number_format($after, 3, '.', ''),
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'notes' => $notes,
        ]);
    }

    /**
     * Ledger-only row when quantity does not change (performa settle:
     * stones already left at issue; this records they became a sale).
     */
    public function recordUnchanged(
        Stone $stone,
        StoneMovementType $type,
        User $actor,
        ?Model $reference = null,
        ?string $notes = null
    ): StoneStockMovement {
        $qty = (int) $stone->quantity;

        return StoneStockMovement::query()->create([
            'branch_id' => $stone->branch_id,
            'stone_id' => $stone->id,
            'type' => $type,
            'quantity_change' => 0,
            'quantity_before' => $qty,
            'quantity_after' => $qty,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'notes' => $notes,
        ]);
    }
}
