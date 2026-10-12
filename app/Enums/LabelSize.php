<?php

namespace App\Enums;

/**
 * LabelSize
 *
 * Physical label stock at one branch. Branch-local because each shop buys
 * its own rolls.
 */
enum LabelSize: string
{
    case SMALL = 'small';
    case MEDIUM = 'medium';
    case LARGE = 'large';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::SMALL => 'Small (30 × 20 mm)',
            self::MEDIUM => 'Medium (50 × 25 mm)',
            self::LARGE => 'Large (80 × 40 mm)',
        };
    }

    /**
     * How many label columns fit on A4 with 10 mm margins.
     */
    public function sheetColumns(): int
    {
        return match ($this) {
            self::SMALL => 5,
            self::MEDIUM => 3,
            self::LARGE => 2,
        };
    }

    /**
     * How many identical barcodes fill one A4 sheet under the name heading.
     */
    public function sheetCapacity(bool $qr = false): int
    {
        $rows = $qr
            ? match ($this) {
                self::SMALL => 6,
                self::MEDIUM => 4,
                self::LARGE => 3,
            }
            : match ($this) {
                self::SMALL => 8,
                self::MEDIUM => 8,
                self::LARGE => 5,
            };

        return $this->sheetColumns() * $rows;
    }

    /**
     * Copies to print. A requested count wins; otherwise fill the sheet.
     * labels_per_sheet is used only when the branch set more than 1.
     */
    public function copyCount(?int $branchId, int $requestedCopies, bool $qr = false): int
    {
        $capacity = $this->sheetCapacity($qr);

        if ($requestedCopies > 0) {
            return max(1, min(48, $requestedCopies));
        }

        $configured = (int) branch_setting('labels_per_sheet', $branchId, 1);
        if ($configured > 1) {
            return max(1, min($capacity, $configured));
        }

        return $capacity;
    }
}
