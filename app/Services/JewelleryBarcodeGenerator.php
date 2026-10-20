<?php

namespace App\Services;

use App\Models\JewelleryItem;
use App\Models\Stone;
use RuntimeException;

/**
 * JewelleryBarcodeGenerator
 *
 * Issues the scannable number printed under a jewellery piece's barcode: a
 * random six digit value, independent of the JWL- item code, exactly like a
 * stone's packet barcode.
 *
 * The till and the stone intake scan one field for both kinds of goods, so a
 * number is never reused across the two tables: it is checked against every
 * jewellery piece and every stone, trashed rows included.
 */
class JewelleryBarcodeGenerator
{
    /**
     * Attempts before giving up; six digits leave 900,000 numbers shared with stones.
     */
    private const MAX_ATTEMPTS = 60;

    /**
     * Generate an unused barcode number.
     *
     * @throws RuntimeException When no free number is found
     */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = (string) random_int(100000, 999999);

            if (! $this->exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not generate a unique jewellery barcode.');
    }

    /**
     * Whether any piece or stone, trashed or not, already carries this number.
     */
    private function exists(string $barcode): bool
    {
        return JewelleryItem::query()->withoutGlobalScopes()->withTrashed()->where('barcode', $barcode)->exists()
            || Stone::query()->withoutGlobalScopes()->withTrashed()->where('barcode', $barcode)->exists();
    }
}
