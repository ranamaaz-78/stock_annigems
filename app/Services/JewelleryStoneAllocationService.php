<?php

namespace App\Services;

use App\Enums\StoneMovementType;
use App\Enums\StonePricingMode;
use App\Models\JewelleryItem;
use App\Models\JewelleryItemStone;
use App\Models\Stone;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * JewelleryStoneAllocationService
 *
 * Applies stone stock deltas when a jewellery piece is created, edited, or
 * deleted. Unchanged quantities produce no movement rows. Quantity and weight
 * are written only through StoneStockService.
 *
 * A piece or carat lot is allocated in pieces; a per-gram lot is allocated in
 * grams and its allocation row keeps quantity 1. Each row also keeps the
 * lot's rate and the cost it comes to (StonePricingService), taken at
 * allocation time like the other snapshots.
 *
 * Snapshots on jewellery_item_stones are taken from the live catalog at
 * allocation time (or kept for rows whose quantity did not increase).
 */
class JewelleryStoneAllocationService
{
    public function __construct(
        private StoneStockService $stockService,
        private JewelleryWeightService $weightService,
        private StonePricingService $pricingService,
    ) {}

    /**
     * Merge duplicate stone UUIDs by summing quantity and grams (scan-twice
     * behaviour).
     *
     * @param  list<array{uuid: string, quantity?: int, weight_grams?: float|string|null}>  $rows
     * @return list<array{uuid: string, quantity: int, weight_grams: float}>
     */
    public function mergeRows(array $rows): array
    {
        $merged = [];

        foreach ($rows as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            $qty = (int) ($row['quantity'] ?? 0);
            $grams = round((float) ($row['weight_grams'] ?? 0), 3);
            if ($uuid === '' || ($qty < 1 && $grams <= 0)) {
                continue;
            }
            $merged[$uuid] = [
                'quantity' => ($merged[$uuid]['quantity'] ?? 0) + $qty,
                'weight_grams' => round(($merged[$uuid]['weight_grams'] ?? 0) + $grams, 3),
            ];
        }

        $out = [];
        foreach ($merged as $uuid => $amount) {
            $out[] = ['uuid' => $uuid, 'quantity' => $amount['quantity'], 'weight_grams' => $amount['weight_grams']];
        }

        return $out;
    }

    /**
     * Create allocations on a new item (all deltas are positive).
     *
     * @param  list<array{uuid: string, quantity?: int, weight_grams?: float|string|null}>  $rows
     */
    public function allocateOnCreate(JewelleryItem $item, array $rows, User $actor): void
    {
        $this->applyDelta($item, [], $this->mergeRows($rows), $actor);
    }

    /**
     * Apply delta between existing allocation and the submitted set.
     *
     * @param  list<array{uuid: string, quantity?: int, weight_grams?: float|string|null}>  $rows
     */
    public function syncOnUpdate(JewelleryItem $item, array $rows, User $actor): bool
    {
        $old = [];
        foreach ($item->itemStones()->get() as $allocation) {
            $stone = Stone::query()->withoutGlobalScopes()->find($allocation->stone_id);
            if ($stone) {
                $old[(string) $stone->uuid] = [
                    'quantity' => (int) $allocation->quantity,
                    'weight_grams' => round((float) $allocation->weight_grams, 3),
                ];
            }
        }

        return $this->applyDelta($item, $old, $this->mergeRows($rows), $actor);
    }

    /**
     * Return every allocated stone to inventory (item soft-delete).
     *
     * Counts pieces for piece lots and one for each gram lot.
     */
    public function returnAll(JewelleryItem $item, User $actor): int
    {
        $returned = 0;

        foreach ($item->itemStones()->with('stone')->get() as $allocation) {
            $qty = (int) $allocation->quantity;
            $grams = round((float) $allocation->weight_grams, 3);
            $stone = Stone::query()->withoutGlobalScopes()->lockForUpdate()->find($allocation->stone_id);
            $note = 'Returned because jewellery item '.$item->item_code.' was deleted.';

            if ($stone && $grams > 0) {
                $this->stockService->applyWeight(
                    $stone,
                    StoneMovementType::RETURNED_FROM_ITEM,
                    $grams,
                    $actor,
                    $item,
                    $note
                );
                $returned += 1;
            } elseif ($stone && $qty > 0) {
                $this->stockService->apply(
                    $stone,
                    StoneMovementType::RETURNED_FROM_ITEM,
                    $qty,
                    $actor,
                    $item,
                    $note
                );
                $returned += $qty;
            }
            $allocation->delete();
        }

        return $returned;
    }

    /**
     * Apply quantity deltas, then refresh stone carat/gram totals only.
     * Typed metal weight (net_weight) is left untouched.
     *
     * @param  array<string, array{quantity: int, weight_grams: float}>  $oldByUuid
     * @param  list<array{uuid: string, quantity: int, weight_grams: float}>  $newRows
     */
    private function applyDelta(JewelleryItem $item, array $oldByUuid, array $newRows, User $actor): bool
    {
        $newByUuid = [];
        foreach ($newRows as $row) {
            $newByUuid[$row['uuid']] = ['quantity' => $row['quantity'], 'weight_grams' => $row['weight_grams']];
        }

        $uuids = array_values(array_unique(array_merge(array_keys($oldByUuid), array_keys($newByUuid))));
        $changed = false;

        foreach ($uuids as $uuid) {
            $stone = Stone::query()
                ->withoutGlobalScopes()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if (! $stone) {
                throw ValidationException::withMessages([
                    'stones' => __('A selected stone could not be found.'),
                ]);
            }

            if ((int) $stone->branch_id !== (int) $item->branch_id) {
                throw ValidationException::withMessages([
                    'stones' => __('Barcode not found.'),
                ]);
            }

            $isGram = $stone->pricing_mode === StonePricingMode::PER_GRAM;
            $present = isset($newByUuid[$uuid]);

            // A gram lot is counted in grams only, a piece lot in pieces only.
            $oldQty = $isGram ? (isset($oldByUuid[$uuid]) ? 1 : 0) : ($oldByUuid[$uuid]['quantity'] ?? 0);
            $newQty = $isGram ? ($present ? 1 : 0) : ($newByUuid[$uuid]['quantity'] ?? 0);
            $oldGrams = $isGram ? ($oldByUuid[$uuid]['weight_grams'] ?? 0.0) : 0.0;
            $newGrams = $isGram ? ($newByUuid[$uuid]['weight_grams'] ?? 0.0) : 0.0;

            if (isset($oldByUuid[$uuid]) && $isGram !== ($oldByUuid[$uuid]['weight_grams'] > 0)) {
                throw ValidationException::withMessages([
                    'stones' => __(':code changed how it is counted since it was added. Remove it and add it again.', ['code' => $stone->stone_code]),
                ]);
            }

            if ($isGram && $present && $newGrams <= 0) {
                throw ValidationException::withMessages([
                    'stones' => __('Enter the grams of :code used in this piece.', ['code' => $stone->stone_code]),
                ]);
            }

            if (! $isGram && $present && $newQty < 1) {
                throw ValidationException::withMessages([
                    'stones' => __('Enter how many pieces of :code are used in this piece.', ['code' => $stone->stone_code]),
                ]);
            }

            $delta = $newQty - $oldQty;
            $deltaGrams = round($newGrams - $oldGrams, 3);

            if ($delta === 0 && $newQty === 0) {
                continue;
            }

            $increased = $isGram ? $deltaGrams > 0 : $delta > 0;

            if ($increased) {
                if (! $stone->is_active) {
                    throw ValidationException::withMessages([
                        'stones' => __(':code is deactivated and cannot be allocated.', ['code' => $stone->stone_code]),
                    ]);
                }

                if ($isGram) {
                    $availableGrams = round((float) ($stone->weight_grams ?? 0), 3);
                    if ($availableGrams < $deltaGrams) {
                        throw ValidationException::withMessages([
                            'stones' => __(':code has only :availableGrams g available; :deltaGrams g more requested.', ['code' => $stone->stone_code, 'availableGrams' => $availableGrams, 'deltaGrams' => $deltaGrams]),
                        ]);
                    }

                    $this->stockService->applyWeight(
                        $stone,
                        StoneMovementType::USED_IN_ITEM,
                        -$deltaGrams,
                        $actor,
                        $item,
                        'Allocated to '.$item->item_code
                    );
                } else {
                    $available = (int) $stone->quantity;
                    if ($available < $delta) {
                        throw ValidationException::withMessages([
                            'stones' => __(':code has only :available available; :delta more requested.', ['code' => $stone->stone_code, 'available' => $available, 'delta' => $delta]),
                        ]);
                    }

                    $this->stockService->apply(
                        $stone,
                        StoneMovementType::USED_IN_ITEM,
                        -$delta,
                        $actor,
                        $item,
                        'Allocated to '.$item->item_code
                    );
                }
                $changed = true;
            } elseif ($isGram ? $deltaGrams < 0 : $delta < 0) {
                if ($isGram) {
                    $this->stockService->applyWeight(
                        $stone,
                        StoneMovementType::RETURNED_FROM_ITEM,
                        abs($deltaGrams),
                        $actor,
                        $item,
                        'Returned from '.$item->item_code
                    );
                } else {
                    $this->stockService->apply(
                        $stone,
                        StoneMovementType::RETURNED_FROM_ITEM,
                        abs($delta),
                        $actor,
                        $item,
                        'Returned from '.$item->item_code
                    );
                }
                $changed = true;
            }

            $existing = JewelleryItemStone::query()
                ->where('jewellery_item_id', $item->id)
                ->where('stone_id', $stone->id)
                ->first();

            if ($newQty === 0) {
                $existing?->delete();

                continue;
            }

            $snapshot = $this->snapshotFrom($stone, $newQty, $newGrams, $existing, $increased);

            if ($existing) {
                $existing->fill($snapshot);
                $existing->save();
            } else {
                JewelleryItemStone::query()->create(array_merge($snapshot, [
                    'jewellery_item_id' => $item->id,
                    'stone_id' => $stone->id,
                ]));
            }
        }

        $item->load('itemStones');
        // Stone totals only — never overwrite typed metal/net weight.
        $weights = $this->weightService->derive($item->itemStones->map(
            fn (JewelleryItemStone $row): array => [
                'carat' => (float) $row->carat_snapshot,
                'quantity' => (int) $row->quantity,
                'grams' => (float) $row->weight_grams,
            ]
        )->all());

        if ((float) $weights['stone_weight_grams'] > (float) $item->gross_weight + 0.0005) {
            throw ValidationException::withMessages([
                'gross_weight' => __('Stone weight in grams cannot exceed gross weight. That is physically impossible — check carat or gross weight.'),
            ]);
        }

        $item->fill($weights);
        $item->save();

        return $changed;
    }

    /**
     * Keep existing snapshots when quantity only decreased or stayed; refresh
     * from the live catalog when quantity increased or the row is new.
     *
     * The cost follows the same rule: a refreshed row is priced at the lot's
     * current rate, a kept row is re-costed at the rate it already holds. A
     * row from before costs were kept has no rate to hold and is priced now.
     *
     * @return array<string, mixed>
     */
    private function snapshotFrom(Stone $stone, int $quantity, float $grams, ?JewelleryItemStone $existing, bool $refresh): array
    {
        $carat = $refresh || ! $existing
            ? (float) $stone->carat
            : (float) $existing->carat_snapshot;

        if ($refresh || ! $existing || $existing->pricing_mode === null) {
            $cost = $this->pricingService->allocationCost($stone, $quantity, $grams);
        } else {
            $cost = [
                'pricing_mode' => $existing->pricing_mode,
                'unit_rate' => (float) $existing->unit_rate,
                'line_cost' => $this->pricingService->costAtRate(
                    $existing->pricing_mode,
                    (float) $existing->unit_rate,
                    $carat,
                    $quantity,
                    $grams
                ),
            ];
        }

        return [
            'quantity' => $quantity,
            'pricing_mode' => $cost['pricing_mode'],
            'unit_rate' => number_format($cost['unit_rate'], 3, '.', ''),
            'weight_grams' => $grams > 0 ? number_format($grams, 3, '.', '') : null,
            'line_cost' => number_format($cost['line_cost'], 3, '.', ''),
            'stone_code_snapshot' => $refresh || ! $existing ? $stone->stone_code : $existing->stone_code_snapshot,
            'stone_name_snapshot' => $refresh || ! $existing ? $stone->name : $existing->stone_name_snapshot,
            'carat_snapshot' => number_format($carat, 3, '.', ''),
            // Lots describe themselves with a shape and a size rather than a
            // catalog of colour/clarity/cut, so only those two carry a value.
            // The remaining snapshot columns stay for historical rows.
            'stone_type_snapshot' => $refresh || ! $existing ? (string) $stone->shape : $existing->stone_type_snapshot,
            'shape_snapshot' => $refresh || ! $existing ? $stone->shape : $existing->shape_snapshot,
            'color_snapshot' => $existing?->color_snapshot,
            'clarity_snapshot' => $existing?->clarity_snapshot,
            'cut_snapshot' => $refresh || ! $existing ? $stone->dimension : $existing->cut_snapshot,
            'origin_snapshot' => $existing?->origin_snapshot,
            'total_carat' => number_format($carat * $quantity, 3, '.', ''),
        ];
    }
}
