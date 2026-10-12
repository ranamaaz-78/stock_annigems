<?php

namespace App\Services;

use App\Enums\JewelleryItemStatus;
use App\Models\JewelleryImage;
use App\Models\JewelleryItem;
use App\Models\JewelleryItemStone;

/**
 * PublicJewelleryItemService
 *
 * Safe payload for the unauthenticated product page. Never includes price,
 * notes, branch internals, staff, stone catalog codes, or attribution.
 */
class PublicJewelleryItemService
{
    /**
     * Resolve by public token. Missing token is the caller's 404.
     */
    public function findByToken(string $token): ?JewelleryItem
    {
        return JewelleryItem::query()
            ->withoutGlobalScopes()
            ->with(['category', 'subCategory', 'metalType', 'purity', 'itemStones', 'images'])
            ->where('qr_token', $token)
            ->first();
    }

    /**
     * Whether the public page may show full detail.
     */
    public function isPubliclyVisible(JewelleryItem $item): bool
    {
        $status = $item->status instanceof JewelleryItemStatus
            ? $item->status
            : JewelleryItemStatus::tryFrom((string) $item->status);

        return $status !== null && in_array($status, JewelleryItemStatus::publiclyVisible(), true);
    }

    /**
     * Fields allowed on the public page.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(JewelleryItem $item): array
    {
        return [
            'name' => $item->name,
            'category' => $item->category?->name,
            'sub_category' => $item->subCategory?->name,
            'metal' => $item->metalType?->name,
            'purity' => $item->purity?->name,
            'gross_weight' => (string) $item->gross_weight,
            'net_weight' => (string) $item->net_weight,
            'status' => $item->status instanceof JewelleryItemStatus ? $item->status->label() : (string) $item->status,
            'images' => $item->images->values()->map(fn (JewelleryImage $image, int $index): array => [
                'index' => $index,
                'is_primary' => $image->is_primary,
            ])->all(),
            'stones' => $item->itemStones->map(fn (JewelleryItemStone $row): array => [
                'type' => $row->stone_type_snapshot,
                'shape' => $row->shape_snapshot,
                'carat' => (string) $row->carat_snapshot,
                'color' => $row->color_snapshot,
                'clarity' => $row->clarity_snapshot,
                'quantity' => (int) $row->quantity,
            ])->values()->all(),
        ];
    }
}
