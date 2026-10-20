<?php

namespace App\Services;

use App\Models\Stone;
use RuntimeException;

/**
 * StoneBarcodeGenerator
 *
 * Issues the scannable packet number. It is a random six digit value,
 * deliberately unrelated to STN-000001: the business code is sequential and
 * guessable, a barcode is not, and the two must stay independent so a code
 * change never invalidates printed labels.
 *
 * Uniqueness is system-wide (the stones.barcode unique index) and checked
 * against soft-deleted rows too, so a retired label is never reprinted.
 */
class StoneBarcodeGenerator
{
    /**
     * Attempts before giving up. Six digits leave 900,000 numbers, so a draw
     * only collides once the table is crowded; run out of attempts and the
     * numbers need widening.
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
            $candidate = $this->candidate();

            if (! $this->exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not generate a unique stone barcode.');
    }

    /**
     * Generate a batch, keeping them unique against each other as well as
     * the table — an import writes many rows before any of them is saved.
     *
     * @return list<string>
     */
    public function generateMany(int $count): array
    {
        $codes = [];

        while (count($codes) < $count) {
            $candidate = $this->generate();

            if (! in_array($candidate, $codes, true)) {
                $codes[] = $candidate;
            }
        }

        return $codes;
    }

    /**
     * One random six digit number (100000-999999). It never starts with
     * zero, so the digit count survives any place it is read as a number.
     */
    private function candidate(): string
    {
        return (string) random_int(100000, 999999);
    }

    /**
     * Whether this number is already on a stone, including trashed rows.
     */
    private function exists(string $barcode): bool
    {
        return Stone::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('barcode', $barcode)
            ->exists()
            || \App\Models\JewelleryItem::query()->withoutGlobalScopes()->withTrashed()->where('barcode', $barcode)->exists();
    }
}
