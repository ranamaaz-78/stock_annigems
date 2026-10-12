<?php

namespace App\Services;

use App\Enums\StoneMovementType;
use App\Models\JewelleryItem;
use App\Models\JewelleryItemStone;
use App\Models\Stone;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * JewelleryStoneAllocationService
 *
 * Applies stone stock deltas when a jewellery piece is created, edited, or
 * deleted. Unchanged quantities produce no movement rows. Quantity is written
 * only through StoneStockService.
 *
 * Snapshots on jewellery_item_stones are taken from the live catalog at
 * allocation time (or kept for rows whose quantity did not increase).
 */
class JewelleryStoneAllocationService
{
    public function __construct(
        private StoneStockService $stockService,
        private JewelleryWeightService $weightService,
    ) {}

    /**
     * Merge duplicate stone UUIDs by summing quantity (scan-twice behaviour).
     *
     * @param  list<array{uuid: string, quantity: int}>  $rows
     * @return list<array{uuid: string, quantity: int}>
     */
    public function mergeRows(array $rows): array
    {
        $merged = [];

        foreach ($rows as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            $qty = (int) ($row['quantity'] ?? 0);
            if ($uuid === '' || $qty < 1) {
                continue;
            }
            $merged[$uuid] = ($merged[$uuid] ?? 0) + $qty;
        }

        $out = [];
        foreach ($merged as $uuid => $quantity) {
            $out[] = ['uuid' => $uuid, 'quantity' => $quantity];
        }

        return $out;
    }

    /**
     * Create allocations on a new item (all deltas are positive).
     *
     * @param  list<array{uuid: string, quantity: int}>  $rows
     */
    public function allocateOnCreate(JewelleryItem $item, array $rows, User $actor): void
    {
        $this->applyDelta($item, [], $this->mergeRows($rows), $actor);
    }

    /**
     * Apply delta between existing allocation and the submitted set.
     *
     * @param  list<array{uuid: string, quantity: int}>  $rows
     */
    public function syncOnUpdate(JewelleryItem $item, array $rows, User $actor): bool
    {
        $old = [];
        foreach ($item->itemStones()->get() as $allocation) {
            $stone = Stone::query()->withoutGlobalScopes()->find($allocation->stone_id);
            if ($stone) {
                $old[(string) $stone->uuid] = (int) $allocation->quantity;
            }
        }

        return $this->applyDelta($item, $old, $this->mergeRows($rows), $actor);
    }

    /**
     * Return every allocated stone to inventory (item soft-delete).
     */
    public function returnAll(JewelleryItem $item, User $actor): int
    {
        $returned = 0;

        foreach ($item->itemStones()->with('stone')->get() as $allocation) {
            $qty = (int) $allocation->quantity;
            $stone = Stone::query()->withoutGlobalScopes()->lockForUpdate()->find($allocation->stone_id);
            if ($stone && $qty > 0) {
                $this->stockService->apply(
                    $stone,
                    StoneMovementType::RETURNED_FROM_ITEM,
                    $qty,
                    $actor,
                    $item,
                    'Returned because jewellery item '.$item->item_code.' was deleted.'
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
     * @param  array<string, int>  $oldByUuid
     * @param  list<array{uuid: string, quantity: int}>  $newRows
     */
    private function applyDelta(JewelleryItem $item, array $oldByUuid, array $newRows, User $actor): bool
    {
        $newByUuid = [];
        foreach ($newRows as $row) {
            $newByUuid[$row['uuid']] = $row['quantity'];
        }

        $uuids = array_values(array_unique(array_merge(array_keys($oldByUuid), array_keys($newByUuid))));
        $changed = false;

        foreach ($uuids as $uuid) {
            $oldQty = $oldByUuid[$uuid] ?? 0;
            $newQty = $newByUuid[$uuid] ?? 0;
            $delta = $newQty - $oldQty;

            if ($delta === 0 && $newQty === 0) {
                continue;
            }

            $stone = Stone::query()
                ->withoutGlobalScopes()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if (! $stone) {
                throw ValidationException::withMessages([
                    'stones' => 'A selected stone could not be found.',
                ]);
            }

            if ((int) $stone->branch_id !== (int) $item->branch_id) {
                throw ValidationException::withMessages([
                    'stones' => 'Barcode not found.',
                ]);
            }

            if ($delta > 0) {
                if (! $stone->is_active) {
                    throw ValidationException::withMessages([
                        'stones' => "{$stone->stone_code} is deactivated and cannot be allocated.",
                    ]);
                }

                $available = (int) $stone->quantity;
                if ($available < $delta) {
                    throw ValidationException::withMessages([
                        'stones' => "{$stone->stone_code} has only {$available} available; {$delta} more requested.",
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
                $changed = true;
            } elseif ($delta < 0) {
                $this->stockService->apply(
                    $stone,
                    StoneMovementType::RETURNED_FROM_ITEM,
                    abs($delta),
                    $actor,
                    $item,
                    'Returned from '.$item->item_code
                );
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

            $snapshot = $this->snapshotFrom($stone, $newQty, $existing, $delta > 0);

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
            ]
        )->all());

        if ((float) $weights['stone_weight_grams'] > (float) $item->gross_weight + 0.0005) {
            throw ValidationException::withMessages([
                'gross_weight' => 'Stone weight in grams cannot exceed gross weight. That is physically impossible — check carat or gross weight.',
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
     * @return array<string, mixed>
     */
    private function snapshotFrom(Stone $stone, int $quantity, ?JewelleryItemStone $existing, bool $refresh): array
    {
        $carat = $refresh || ! $existing
            ? (float) $stone->carat
            : (float) $existing->carat_snapshot;

        return [
            'quantity' => $quantity,
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
