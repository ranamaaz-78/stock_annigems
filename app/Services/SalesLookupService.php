<?php

namespace App\Services;

use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Models\JewelleryItem;
use App\Models\Stone;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * SalesLookupService
 *
 * Unified barcode / short-code / name lookup for quotation and performa
 * workspaces. Tries jewellery first, then loose stone. Always branch-scoped.
 */
class SalesLookupService
{
    /**
     * Resolve a scan, short code, SKU, or unique name to an addable line.
     *
     * @return array{status: string, message?: string, line?: array<string, mixed>}
     */
    public function lookup(string $code, User $actor): array
    {
        $code = trim($code);
        $branchId = (int) $actor->branch_id;
        $short = strtoupper($code);

        $jewellery = $this->findJewelleryByCode($branchId, $code);

        if ($jewellery) {
            return $this->jewelleryLookupResult($jewellery);
        }

        $stone = $this->findStoneByCode($branchId, $code, $short);

        if ($stone) {
            return $this->stoneLookupResult($stone);
        }

        $named = $this->findUniqueByName($branchId, $code);

        if (($named['status'] ?? '') === 'ambiguous') {
            return $named;
        }

        if (($named['jewellery'] ?? null) instanceof JewelleryItem) {
            return $this->jewelleryLookupResult($named['jewellery']);
        }

        if (($named['stone'] ?? null) instanceof Stone) {
            return $this->stoneLookupResult($named['stone']);
        }

        // Cross-branch existence → generic not found (no leak)
        return ['status' => 'not_found', 'message' => 'No item or stone matches that code or name in this branch.'];
    }

    /**
     * Typeahead for add-by-name. Returns ready-to-add line payloads.
     *
     * Rows carry both a flat `label` (legacy single-line pickers) and the
     * split `code` / `title` / `meta` strings the POS dropdown renders, so the
     * display text is built once here instead of in the browser.
     *
     * @return list<array{uuid: string, label: string, code: string, title: string, meta: string, line_type: string, line: array<string, mixed>}>
     */
    public function search(string $term, User $actor, int $limit = 12): array
    {
        $term = trim($term);
        $branchId = (int) $actor->branch_id;
        $like = '%'.$term.'%';
        $results = [];

        $jewellery = JewelleryItem::query()
            ->withoutGlobalScopes()
            ->with(['category', 'metalType', 'purity', 'itemStones'])
            ->where('branch_id', $branchId)
            ->where('status', JewelleryItemStatus::AVAILABLE)
            ->when($term !== '', function ($query) use ($like): void {
                $query->where(function ($inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('item_code', 'like', $like)
                        ->orWhere('barcode', 'like', $like);
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        foreach ($jewellery as $item) {
            $results[] = [
                'uuid' => $item->uuid,
                'label' => implode(' · ', array_filter([
                    $item->item_code,
                    $item->name,
                    trim(($item->metalType?->name ?? '').' '.($item->purity?->name ?? '')),
                ])),
                'haystack' => mb_strtolower(implode(' ', array_filter([
                    $item->item_code,
                    $item->barcode,
                    $item->name,
                    $item->metalType?->name,
                    $item->purity?->name,
                ]))),
                'code' => (string) $item->item_code,
                'title' => (string) $item->name,
                'meta' => implode(' · ', array_filter([
                    $item->metalType?->name,
                    $item->purity?->name,
                    $item->category?->name,
                ])),
                'line_type' => LineType::JEWELLERY_ITEM->value,
                'line' => $this->jewelleryPayload($item),
            ];
        }

        $stones = Stone::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('quantity', '>=', 1)
            ->when($term !== '', function ($query) use ($like): void {
                $query->where(function ($inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('stone_code', 'like', $like)
                        ->orWhere('barcode', 'like', $like)
                        ->orWhere('tray_no', 'like', $like);
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        foreach ($stones as $stone) {
            $results[] = [
                'uuid' => $stone->uuid,
                'label' => implode(' · ', array_filter([
                    $stone->stone_code,
                    $stone->name,
                    '('.$stone->quantity.')',
                ])),
                'haystack' => mb_strtolower(implode(' ', array_filter([
                    $stone->stone_code,
                    $stone->barcode,
                    $stone->tray_no,
                    $stone->name,
                ]))),
                'code' => (string) $stone->stone_code,
                'title' => (string) $stone->name,
                'meta' => implode(' · ', array_filter([
                    $stone->shape,
                    $stone->dimension,
                    $stone->tray_no,
                ])),
                'line_type' => LineType::LOOSE_STONE->value,
                'line' => $this->stonePayload($stone),
            ];
        }

        return $results;
    }

    /**
     * Full addable catalog for POS — one payload, then the browser filters.
     *
     * @return list<array{uuid: string, label: string, haystack: string, line_type: string, line: array<string, mixed>}>
     */
    public function catalog(User $actor): array
    {
        return $this->search('', $actor, 2000);
    }

    /**
     * Match a jewellery piece by barcode or generated item code.
     */
    private function findJewelleryByCode(int $branchId, string $code): ?JewelleryItem
    {
        return JewelleryItem::query()
            ->withoutGlobalScopes()
            ->with(['category', 'metalType', 'purity', 'itemStones'])
            ->where('branch_id', $branchId)
            ->where(function ($q) use ($code): void {
                $q->where('barcode', $code)->orWhere('item_code', $code);
            })
            ->first();
    }

    /**
     * Match a stone lot by its packet barcode or its STN code.
     */
    private function findStoneByCode(int $branchId, string $code, string $short): ?Stone
    {
        return Stone::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where(function ($q) use ($code): void {
                $q->where('barcode', $code)->orWhere('stone_code', $code);
            })
            ->first();
    }

    /**
     * Exact name match when the typed value is not a code. Ambiguous when
     * more than one jewellery piece or stone shares that name.
     *
     * @return array{status?: string, message?: string, jewellery?: JewelleryItem, stone?: Stone}
     */
    private function findUniqueByName(int $branchId, string $name): array
    {
        $jewellery = JewelleryItem::query()
            ->withoutGlobalScopes()
            ->with(['category', 'metalType', 'purity', 'itemStones'])
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get();

        $stones = Stone::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get();

        if ($jewellery->count() + $stones->count() > 1) {
            return [
                'status' => 'ambiguous',
                'message' => 'Several items match that name. Search and pick one.',
            ];
        }

        return [
            'jewellery' => $jewellery->first(),
            'stone' => $stones->first(),
        ];
    }

    /**
     * Available jewellery becomes a line; any other status is rejected.
     *
     * @return array{status: string, message?: string, line?: array<string, mixed>}
     */
    private function jewelleryLookupResult(JewelleryItem $jewellery): array
    {
        if ($jewellery->status !== JewelleryItemStatus::AVAILABLE) {
            return [
                'status' => 'unavailable',
                'message' => "{$jewellery->item_code} is not available ({$jewellery->status->label()}).",
            ];
        }

        return [
            'status' => 'found',
            'line' => $this->jewelleryPayload($jewellery),
        ];
    }

    /**
     * Active in-stock stones become a line; empty or inactive packets fail.
     *
     * @return array{status: string, message?: string, line?: array<string, mixed>}
     */
    private function stoneLookupResult(Stone $stone): array
    {
        if (! $stone->is_active) {
            return ['status' => 'inactive', 'message' => 'This stone is deactivated.'];
        }

        if ((int) $stone->quantity < 1) {
            return ['status' => 'unavailable', 'message' => "{$stone->stone_code} has no stock on hand."];
        }

        return [
            'status' => 'found',
            'line' => $this->stonePayload($stone),
        ];
    }

    /**
     * The price per piece a stone allocation was costed at when the piece was made.
     *
     * A per-gram allocation is one row (quantity 1), so its price is the whole
     * line cost. The snapshot is what the shop paid for the stone, so it is
     * loaded only for users allowed to see cost (items.view_cost); everyone
     * else starts at zero and types the price, as before.
     */
    private function allocationPiecePrice(\App\Models\JewelleryItemStone $allocation): string
    {
        $quantity = (int) $allocation->quantity;

        if ($quantity < 1 || ! auth()->user()?->hasPermission('items.view_cost')) {
            return '0.00';
        }

        return number_format((float) $allocation->line_cost / $quantity, 2, '.', '');
    }

    /**
     * Build jewellery line template from live item.
     *
     * @return array<string, mixed>
     */
    public function jewelleryPayload(JewelleryItem $item): array
    {
        $stones = $item->itemStones->map(fn ($s): array => [
            'jewellery_item_stone_id' => $s->id,
            'stone_name_snapshot' => $s->stone_name_snapshot,
            'stone_type_snapshot' => $s->stone_type_snapshot,
            'carat_snapshot' => (string) $s->carat_snapshot,
            'shape_snapshot' => $s->shape_snapshot,
            'color_snapshot' => $s->color_snapshot,
            'clarity_snapshot' => $s->clarity_snapshot,
            'quantity' => $s->quantity,
            'price_per_piece' => $this->allocationPiecePrice($s),
            'total_price' => '0.00',
        ])->values()->all();

        return [
            'line_type' => LineType::JEWELLERY_ITEM->value,
            'jewellery_item_id' => $item->id,
            'jewellery_item_uuid' => $item->uuid,
            'item_code_snapshot' => $item->item_code,
            'name_snapshot' => $item->name,
            'category_snapshot' => $item->category?->name,
            'metal_snapshot' => $item->metalType?->name,
            'purity_snapshot' => $item->purity?->name,
            'purity_id_snapshot' => $item->purity_id,
            'gross_weight_snapshot' => (string) $item->gross_weight,
            'net_weight_snapshot' => (string) $item->net_weight,
            'quantity' => 1,
            'gold_rate_applied' => '0.00',
            'making_charge_type' => 'none',
            'making_charge_value' => '0.00',
            'stones' => $stones,
        ];
    }

    /**
     * Rebuild the descriptive snapshot of every submitted line from the live
     * records, keeping only the operator's pricing choices from the request.
     *
     * Two reasons this is authoritative rather than trusting the browser:
     * the Form Request cannot whitelist free-text snapshot fields without
     * letting a client print any name, carat, or weight it likes on an
     * invoice; and stripping them instead (Laravel's validated() behaviour)
     * silently stored blank stone names and zero carats.
     *
     * Kept from the request: gold rate, making charge type/value, loose stone
     * quantity, and each stone's price per piece. Everything else — codes,
     * names, purity, weights, stone identity, carats — comes from the DB.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     *
     * @throws \Illuminate\Validation\ValidationException When a line points at
     *                                                    a record outside the actor's branch.
     */
    public function hydrateLines(array $lines, User $actor): array
    {
        $branchId = (int) $actor->branch_id;
        $hydrated = [];

        foreach ($lines as $index => $line) {
            $type = LineType::tryFrom((string) ($line['line_type'] ?? ''));
            $lineNum = $index + 1;

            if ($type === LineType::LOOSE_STONE) {
                $stone = Stone::query()
                    ->withoutGlobalScopes()
                    ->where('branch_id', $branchId)
                    ->whereKey((int) ($line['stone_id'] ?? 0))
                    ->first();

                if (! $stone) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => "Line {$lineNum}: this stone is no longer in your branch. Remove the line and scan it again.",
                    ]);
                }

                $hydrated[] = array_merge($this->stonePayload($stone), [
                    'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                    'price_per_piece' => $line['price_per_piece'] ?? '0.00',
                ]);

                continue;
            }

            $item = JewelleryItem::query()
                ->withoutGlobalScopes()
                ->with(['category', 'metalType', 'purity', 'itemStones'])
                ->where('branch_id', $branchId)
                ->whereKey((int) ($line['jewellery_item_id'] ?? 0))
                ->first();

            if (! $item) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => "Line {$lineNum}: this item is no longer in your branch. Remove the line and scan it again.",
                ]);
            }

            $base = $this->jewelleryPayload($item);

            $hydrated[] = array_merge($base, [
                'gold_rate_applied' => $line['gold_rate_applied'] ?? '0.00',
                'making_charge_type' => $line['making_charge_type'] ?? 'none',
                'making_charge_value' => $line['making_charge_value'] ?? '0.00',
                'stones' => $this->applyStonePrices($base['stones'], $line['stones'] ?? []),
            ]);
        }

        return $hydrated;
    }

    /**
     * Copy submitted per-piece prices onto the item's real stone allocations.
     *
     * Matched on jewellery_item_stone_id where the client sent one, otherwise
     * by position — the workspace renders allocations in the order the lookup
     * returned them. Unmatched allocations simply keep a zero price.
     *
     * @param  list<array<string, mixed>>  $allocations  Authoritative rows from jewelleryPayload()
     * @param  list<array<string, mixed>>  $submitted    Stone entries from the request
     * @return list<array<string, mixed>>
     */
    private function applyStonePrices(array $allocations, array $submitted): array
    {
        $submitted = array_values($submitted);

        $pricesById = [];
        foreach ($submitted as $stone) {
            $id = $stone['jewellery_item_stone_id'] ?? null;
            if ($id !== null) {
                $pricesById[(int) $id] = $stone['price_per_piece'] ?? '0.00';
            }
        }

        foreach ($allocations as $position => $allocation) {
            $id = (int) ($allocation['jewellery_item_stone_id'] ?? 0);

            // Nothing submitted for a stone keeps the price it was loaded with.
            $allocations[$position]['price_per_piece'] = $pricesById[$id]
                ?? ($submitted[$position]['price_per_piece'] ?? $allocation['price_per_piece'] ?? '0.00');
        }

        return $allocations;
    }

    /**
     * What one piece of a lot is bought for, to start a loose-stone line from.
     *
     * Per carat is the lot's piece price (carat x rate) and per piece is its
     * rate. A per-gram lot is weighed, not counted, so it has no price per
     * piece. Like the jewellery stone prices, this is purchase cost and is
     * loaded only for users who may see cost (items.view_cost); everyone else
     * starts at zero and types the price.
     */
    private function stonePiecePrice(Stone $stone): string
    {
        if (! auth()->user()?->hasPermission('items.view_cost')) {
            return '0.00';
        }

        $price = match ($stone->pricing_mode) {
            \App\Enums\StonePricingMode::PER_CARAT => $stone->piece_price,
            \App\Enums\StonePricingMode::PER_PIECE => $stone->price_per_piece,
            default => null,
        };

        return number_format((float) $price, 2, '.', '');
    }

    /**
     * Build loose stone line template.
     *
     * @return array<string, mixed>
     */
    public function stonePayload(Stone $stone): array
    {
        return [
            'line_type' => LineType::LOOSE_STONE->value,
            'stone_id' => $stone->id,
            'stone_uuid' => $stone->uuid,
            'item_code_snapshot' => $stone->stone_code,
            'name_snapshot' => $stone->name,
            // Lots have no catalog type any more; the shape is what an
            // operator recognises on the receipt line.
            'category_snapshot' => $stone->shape,
            'quantity' => 1,
            'available_quantity' => (int) $stone->quantity,
            'price_per_piece' => $this->stonePiecePrice($stone),
            'stones' => [],
        ];
    }
}
