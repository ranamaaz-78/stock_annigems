<?php

namespace Tests\Unit;

use App\Enums\StonePricingMode;
use App\Services\StonePricingService;
use Tests\TestCase;

/**
 * The three locked stone formulas. These assertions are the contract the
 * browser preview and the import both have to match.
 */
class StonePricingServiceTest extends TestCase
{
    private StonePricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new StonePricingService;
    }

    /**
     * Per carat prices one piece, then multiplies by the piece count.
     */
    public function test_per_carat_prices_each_piece_then_multiplies_by_pieces(): void
    {
        $result = $this->pricing->calculate([
            'pricing_mode' => StonePricingMode::PER_CARAT,
            'carat' => '2.100',
            'price_per_carat' => '2.0000',
            'pieces' => 2,
        ]);

        $this->assertSame([], $result['issues']);
        $this->assertSame(4.2, $result['piece_price']);
        $this->assertSame(8.4, $result['total_cost']);
        $this->assertStringContainsString('2.1 ct x 2 = 4.2 per piece', $result['cost_formula']);
        $this->assertStringContainsString('x 2 pcs = 8.4', $result['cost_formula']);
    }

    /**
     * A single piece still reports its own piece price.
     */
    public function test_per_carat_single_piece_totals_the_piece_price(): void
    {
        $result = $this->pricing->calculate([
            'pricing_mode' => StonePricingMode::PER_CARAT,
            'carat' => '5.400',
            'price_per_carat' => '1.0000',
            'pieces' => 1,
        ]);

        $this->assertSame(5.4, $result['piece_price']);
        $this->assertSame(5.4, $result['total_cost']);
    }

    /**
     * Per piece multiplies the count by the piece rate.
     */
    public function test_per_piece_multiplies_pieces_by_rate(): void
    {
        $result = $this->pricing->calculate([
            'pricing_mode' => StonePricingMode::PER_PIECE,
            'pieces' => 68,
            'price_per_piece' => '9.0000',
        ]);

        $this->assertSame([], $result['issues']);
        $this->assertSame(612.0, $result['total_cost']);
        $this->assertSame('68 pcs x 9 = 612', $result['cost_formula']);
    }

    /**
     * Per gram multiplies bulk weight by the gram rate.
     */
    public function test_per_gram_multiplies_weight_by_rate(): void
    {
        $result = $this->pricing->calculate([
            'pricing_mode' => StonePricingMode::PER_GRAM,
            'weight_grams' => '150.000',
            'price_per_gram' => '2.5000',
        ]);

        $this->assertSame([], $result['issues']);
        $this->assertNull($result['piece_price']);
        $this->assertSame(375.0, $result['total_cost']);
        $this->assertSame('150 g x 2.5 = 375', $result['cost_formula']);
    }

    /**
     * Pairs are a physical count and never change the money.
     */
    public function test_pairs_do_not_affect_the_total(): void
    {
        $base = [
            'pricing_mode' => StonePricingMode::PER_PIECE,
            'pieces' => 10,
            'price_per_piece' => '5.0000',
        ];

        $without = $this->pricing->calculate($base);
        $with = $this->pricing->calculate($base + ['pairs' => 4]);

        $this->assertSame($without['total_cost'], $with['total_cost']);
    }

    /**
     * Sheet placeholders are not numbers; the row reports why it failed.
     */
    public function test_placeholder_values_leave_the_row_uncosted(): void
    {
        $result = $this->pricing->calculate([
            'pricing_mode' => StonePricingMode::PER_CARAT,
            'carat' => 'PENDING',
            'price_per_carat' => 'N/A',
            'pieces' => 0,
        ]);

        $this->assertSame(0.0, $result['total_cost']);
        $this->assertNull($result['cost_formula']);
        $this->assertCount(3, $result['issues']);
    }

    /**
     * An unknown measurement cell cannot become a price.
     */
    public function test_missing_mode_is_rejected(): void
    {
        $result = $this->pricing->calculate(['pricing_mode' => 'NO COUNT', 'pieces' => 5]);

        $this->assertSame(0.0, $result['total_cost']);
        $this->assertNotEmpty($result['issues']);
    }

    /**
     * Sheet totals are compared with a one-cent tolerance.
     */
    public function test_totals_match_within_a_cent(): void
    {
        $this->assertTrue($this->pricing->matches(750.90, 750.9000000001));
        $this->assertFalse($this->pricing->matches(750.90, 751.0));
    }
}
