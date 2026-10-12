<?php

namespace App\Services;

use App\Enums\StonePricingMode;
use App\Models\Stone;

/**
 * StonePricingService
 *
 * The only place stone money is calculated. Three locked formulas, one per
 * pricing mode:
 *
 *   per carat  piece price = carat x price_per_carat
 *              total       = piece price x pieces
 *   per piece  total       = pieces x price_per_piece
 *   per gram   total       = weight_grams x price_per_gram
 *
 * Pairs are a physical count and never take part in a price. The browser
 * preview mirrors these formulas, but the value stored is always the one
 * this service returns — a client total is never trusted.
 */
class StonePricingService
{
    /**
     * Decimals kept for every stone figure: rates, money, carat and grams.
     * Money is rounded to this many decimals before it is stored or compared.
     */
    public const SCALE = 3;

    /**
     * Largest difference tolerated when comparing our total to the sheet's.
     */
    public const TOLERANCE = 0.01;

    /**
     * Calculate one lot.
     *
     * Returns the piece price (per carat only), the total, a readable
     * formula, and the reasons the row cannot be costed. A row with any
     * issue is left at zero rather than guessing a number.
     *
     * @param  array<string, mixed>  $data Lot fields; missing keys count as blank
     * @return array{
     *     piece_price: float|null,
     *     total_cost: float,
     *     cost_formula: string|null,
     *     issues: list<string>
     * }
     */
    public function calculate(array $data): array
    {
        $mode = $data['pricing_mode'] instanceof StonePricingMode
            ? $data['pricing_mode']
            : StonePricingMode::tryFrom((string) ($data['pricing_mode'] ?? ''));

        if ($mode === null) {
            return $this->failure('Choose a pricing mode: per carat, per piece, or per gram.');
        }

        return match ($mode) {
            StonePricingMode::PER_CARAT => $this->perCarat($data),
            StonePricingMode::PER_PIECE => $this->perPiece($data),
            StonePricingMode::PER_GRAM => $this->perGram($data),
        };
    }

    /**
     * What stock received later at the lot's own rate adds to its cost.
     *
     * The total grows by the received pieces (or grams) at the rate the lot
     * was bought at. A different rate or carat is a different lot, so the
     * lot's rate is never averaged or rewritten here. The formula keeps the
     * running story ("612 + 10 pcs x 9 = 702") within the column width.
     *
     * @param  int  $pieces  Pieces received (piece and carat lots)
     * @param  float  $grams  Grams received (gram lots)
     * @return array{total_cost: float, cost_formula: string}
     */
    public function costAfterReceiving(Stone $stone, int $pieces, float $grams): array
    {
        $isGram = $stone->pricing_mode === StonePricingMode::PER_GRAM;

        $unit = match ($stone->pricing_mode) {
            StonePricingMode::PER_CARAT => (float) $stone->piece_price,
            StonePricingMode::PER_PIECE => (float) $stone->price_per_piece,
            StonePricingMode::PER_GRAM => (float) $stone->price_per_gram,
        };
        $count = $isGram ? $grams : (float) $pieces;

        $previous = (float) $stone->total_cost;
        $total = $this->round($previous + $count * $unit);

        $formula = sprintf(
            '%s + %s %s x %s = %s',
            $this->trim($previous),
            $this->trim($count),
            $isGram ? 'g' : 'pcs',
            $this->trim($unit),
            $this->trim($total)
        );

        return [
            'total_cost' => $total,
            'cost_formula' => mb_substr($formula, 0, 120),
        ];
    }

    /**
     * What a share of a lot costs when it goes into a jewellery piece.
     *
     * Priced in the unit the lot was bought in. `unit_rate` is the lot's own
     * rate in that unit, kept so the piece still explains its cost after the
     * lot's rate is edited.
     *
     * @param  int  $pieces  Pieces used (piece and carat lots)
     * @param  float  $grams  Grams used (gram lots)
     * @return array{pricing_mode: StonePricingMode, unit_rate: float, line_cost: float}
     */
    public function allocationCost(Stone $stone, int $pieces, float $grams): array
    {
        $mode = $stone->pricing_mode ?? StonePricingMode::PER_PIECE;

        $rate = $this->round((float) match ($mode) {
            StonePricingMode::PER_CARAT => $stone->price_per_carat,
            StonePricingMode::PER_PIECE => $stone->price_per_piece,
            StonePricingMode::PER_GRAM => $stone->price_per_gram,
        });

        return [
            'pricing_mode' => $mode,
            'unit_rate' => $rate,
            'line_cost' => $this->costAtRate($mode, $rate, (float) $stone->carat, $pieces, $grams),
        ];
    }

    /**
     * Cost of an allocation at a rate that is already known.
     *
     *   per carat  round(carat x rate) x pieces   (the lot's piece price)
     *   per piece  rate x pieces
     *   per gram   rate x grams
     *
     * Used for a fresh allocation and to re-cost a kept snapshot when only
     * the quantity went down, so the rate never silently follows the lot.
     */
    public function costAtRate(StonePricingMode $mode, float $rate, float $carat, int $pieces, float $grams): float
    {
        return match ($mode) {
            StonePricingMode::PER_CARAT => $this->round($this->round($carat * $rate) * $pieces),
            StonePricingMode::PER_PIECE => $this->round($rate * $pieces),
            StonePricingMode::PER_GRAM => $this->round($rate * $grams),
        };
    }

    /**
     * Whether a stored total still matches what the inputs produce.
     *
     * Used when the sheet supplies its own TOTAL COST column: a mismatch
     * marks the row for the operator instead of silently overwriting.
     */
    public function matches(float $expected, float $actual): bool
    {
        return abs($expected - $actual) <= self::TOLERANCE;
    }

    /**
     * Per carat: price one piece, then multiply by how many pieces.
     *
     * @param  array<string, mixed>  $data
     * @return array{piece_price: float|null, total_cost: float, cost_formula: string|null, issues: list<string>}
     */
    private function perCarat(array $data): array
    {
        $issues = [];
        $carat = $this->positiveNumber($data['carat'] ?? null);
        $rate = $this->positiveNumber($data['price_per_carat'] ?? null);
        $pieces = $this->positiveInt($data['pieces'] ?? null);

        if ($carat === null) {
            $issues[] = 'Per carat needs a carat weight above zero.';
        }
        if ($rate === null) {
            $issues[] = 'Per carat needs a price per carat above zero.';
        }
        if ($pieces === null) {
            $issues[] = 'Per carat needs at least one piece.';
        }

        if ($issues !== []) {
            return $this->failure(...$issues);
        }

        $piecePrice = $this->round($carat * $rate);
        $total = $this->round($piecePrice * $pieces);

        return [
            'piece_price' => $piecePrice,
            'total_cost' => $total,
            'cost_formula' => sprintf(
                '%s ct x %s = %s per piece, x %d pcs = %s',
                $this->trim($carat),
                $this->trim($rate),
                $this->trim($piecePrice),
                $pieces,
                $this->trim($total)
            ),
            'issues' => [],
        ];
    }

    /**
     * Per piece: pieces multiplied by the price of one piece.
     *
     * @param  array<string, mixed>  $data
     * @return array{piece_price: float|null, total_cost: float, cost_formula: string|null, issues: list<string>}
     */
    private function perPiece(array $data): array
    {
        $issues = [];
        $pieces = $this->positiveInt($data['pieces'] ?? null);
        $rate = $this->positiveNumber($data['price_per_piece'] ?? null);

        if ($pieces === null) {
            $issues[] = 'Per piece needs at least one piece.';
        }
        if ($rate === null) {
            $issues[] = 'Per piece needs a price per piece above zero.';
        }

        if ($issues !== []) {
            return $this->failure(...$issues);
        }

        $total = $this->round($pieces * $rate);

        return [
            'piece_price' => $this->round($rate),
            'total_cost' => $total,
            'cost_formula' => sprintf(
                '%d pcs x %s = %s',
                $pieces,
                $this->trim($rate),
                $this->trim($total)
            ),
            'issues' => [],
        ];
    }

    /**
     * Per gram: bulk weight multiplied by the gram rate.
     *
     * @param  array<string, mixed>  $data
     * @return array{piece_price: float|null, total_cost: float, cost_formula: string|null, issues: list<string>}
     */
    private function perGram(array $data): array
    {
        $issues = [];
        $grams = $this->positiveNumber($data['weight_grams'] ?? null);
        $rate = $this->positiveNumber($data['price_per_gram'] ?? null);

        if ($grams === null) {
            $issues[] = 'Per gram needs a weight in grams above zero.';
        }
        if ($rate === null) {
            $issues[] = 'Per gram needs a price per gram above zero.';
        }

        if ($issues !== []) {
            return $this->failure(...$issues);
        }

        $total = $this->round($grams * $rate);

        return [
            'piece_price' => null,
            'total_cost' => $total,
            'cost_formula' => sprintf(
                '%s g x %s = %s',
                $this->trim($grams),
                $this->trim($rate),
                $this->trim($total)
            ),
            'issues' => [],
        ];
    }

    /**
     * A lot that cannot be costed yet.
     *
     * @return array{piece_price: null, total_cost: float, cost_formula: null, issues: list<string>}
     */
    private function failure(string ...$issues): array
    {
        return [
            'piece_price' => null,
            'total_cost' => 0.0,
            'cost_formula' => null,
            'issues' => array_values($issues),
        ];
    }

    /**
     * Numeric value above zero, or null. Sheet placeholders such as "N/A"
     * and "PENDING" are not numbers and fall through to null.
     */
    private function positiveNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        // A sheet can carry more places than are stored. Round first, so the
        // total is worked out from exactly the figure that will be saved.
        $number = round((float) $value, self::SCALE);

        return $number > 0 ? $number : null;
    }

    /**
     * Whole count above zero, or null.
     */
    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    /**
     * Round to the stored scale so PHP float drift never reaches the table.
     */
    private function round(float $value): float
    {
        return round($value, self::SCALE);
    }

    /**
     * Drop trailing zeros so a formula reads 2.1 rather than 2.1000.
     */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, self::SCALE, '.', ''), '0'), '.') ?: '0';
    }
}
