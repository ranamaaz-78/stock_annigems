<?php

namespace App\Services;

use App\Models\Stone;
use RuntimeException;

/**
 * StoneBarcodeGenerator
 *
 * Issues the scannable packet number. It is a random 10 or 11 digit value,
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
     * Attempts before giving up. A collision needs a repeated random draw
     * out of ten billion, so exhausting this means something else is wrong.
     */
    private const MAX_ATTEMPTS = 20;

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
     * One random 10 or 11 digit number that never starts with zero, so the
     * digit count survives any place the value is read as a number.
     */
    private function candidate(): string
    {
        $length = random_int(10, 11);
        $digits = (string) random_int(1, 9);

        for ($i = 1; $i < $length; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
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
            ->exists();
    }
}
