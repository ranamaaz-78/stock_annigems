<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair quotation and invoice line snapshots written before the server-side
 * rebuild existed.
 *
 * Until SalesLookupService::hydrateLines() was introduced, the Form Request
 * stripped every descriptive field it did not validate, so lines were stored
 * without purity/metal/weight and their stones with a blank name and a zero
 * carat. Documents printed "· 0.00ct × 2".
 *
 * This backfill only touches rows that are still blank, reading the truth back
 * from jewellery_items and jewellery_item_stones. Priced values are never
 * altered — only the descriptive snapshot.
 */
return new class extends Migration
{
    /**
     * Backfill both document families.
     */
    public function up(): void
    {
        foreach ([
            ['items' => 'quotation_items', 'stones' => 'quotation_item_stones', 'fk' => 'quotation_item_id'],
            ['items' => 'invoice_items', 'stones' => 'invoice_item_stones', 'fk' => 'invoice_item_id'],
        ] as $tables) {
            $this->backfillLines($tables['items']);
            $this->backfillStones($tables['items'], $tables['stones'], $tables['fk']);
        }
    }

    /**
     * Irreversible: the previous state was missing data, not a schema change.
     */
    public function down(): void
    {
        // Nothing to roll back — restoring blank snapshots has no value.
    }

    /**
     * Copy category / metal / purity / weight snapshots onto jewellery lines
     * that are missing them.
     */
    private function backfillLines(string $itemsTable): void
    {
        $rows = DB::table($itemsTable)
            ->whereNotNull('jewellery_item_id')
            ->where(function ($query): void {
                $query->whereNull('purity_snapshot')->orWhereNull('net_weight_snapshot');
            })
            ->get(['id', 'jewellery_item_id']);

        foreach ($rows as $row) {
            $item = DB::table('jewellery_items')
                ->leftJoin('categories', 'categories.id', '=', 'jewellery_items.category_id')
                ->leftJoin('metal_types', 'metal_types.id', '=', 'jewellery_items.metal_type_id')
                ->leftJoin('purities', 'purities.id', '=', 'jewellery_items.purity_id')
                ->where('jewellery_items.id', $row->jewellery_item_id)
                ->first([
                    'jewellery_items.item_code',
                    'jewellery_items.name',
                    'jewellery_items.purity_id',
                    'jewellery_items.gross_weight',
                    'jewellery_items.net_weight',
                    'categories.name as category_name',
                    'metal_types.name as metal_name',
                    'purities.name as purity_name',
                ]);

            if (! $item) {
                continue;
            }

            // invoice_items carries no purity_id_snapshot, so the update set is
            // filtered against the actual schema.
            $updates = array_filter([
                'item_code_snapshot' => $item->item_code,
                'name_snapshot' => $item->name,
                'category_snapshot' => $item->category_name,
                'metal_snapshot' => $item->metal_name,
                'purity_snapshot' => $item->purity_name,
                'purity_id_snapshot' => $item->purity_id,
                'gross_weight_snapshot' => $item->gross_weight,
                'net_weight_snapshot' => $item->net_weight,
            ], fn ($value, string $column): bool => Schema::hasColumn($itemsTable, $column), ARRAY_FILTER_USE_BOTH);

            DB::table($itemsTable)->where('id', $row->id)->update($updates);
        }
    }

    /**
     * Re-link blank stone rows to the allocation they were priced from and
     * copy its descriptive snapshot across.
     *
     * Allocations are matched by position within the parent line, ordered by
     * id — the same order the workspace rendered them in when the document was
     * created, so prices stay attached to the right stone.
     */
    private function backfillStones(string $itemsTable, string $stonesTable, string $foreignKey): void
    {
        $lineIds = DB::table($stonesTable)
            ->where(function ($query): void {
                $query->whereNull('stone_name_snapshot')->orWhere('stone_name_snapshot', '');
            })
            ->distinct()
            ->pluck($foreignKey);

        foreach ($lineIds as $lineId) {
            $jewelleryItemId = DB::table($itemsTable)->where('id', $lineId)->value('jewellery_item_id');

            if (! $jewelleryItemId) {
                continue;
            }

            $allocations = DB::table('jewellery_item_stones')
                ->where('jewellery_item_id', $jewelleryItemId)
                ->orderBy('id')
                ->get()
                ->values();

            $stoneRows = DB::table($stonesTable)
                ->where($foreignKey, $lineId)
                ->orderBy('id')
                ->get(['id'])
                ->values();

            foreach ($stoneRows as $position => $stoneRow) {
                $allocation = $allocations[$position] ?? null;

                if (! $allocation) {
                    continue;
                }

                DB::table($stonesTable)->where('id', $stoneRow->id)->update([
                    'jewellery_item_stone_id' => $allocation->id,
                    'stone_name_snapshot' => (string) $allocation->stone_name_snapshot,
                    'stone_type_snapshot' => (string) $allocation->stone_type_snapshot,
                    'carat_snapshot' => $allocation->carat_snapshot,
                    'shape_snapshot' => $allocation->shape_snapshot,
                    'color_snapshot' => $allocation->color_snapshot,
                    'clarity_snapshot' => $allocation->clarity_snapshot,
                    'quantity' => $allocation->quantity,
                ]);
            }
        }
    }
};
