<?php

namespace App\Models;

use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Stone
 *
 * One lot from the supplier inventory sheet: a material, its shape and
 * millimetre size, how many pieces or grams it holds, and the rate it was
 * bought at. Lots are never merged — the same material appears many times
 * with different carats, trays, and rates.
 *
 * Money (piece_price, total_cost, cost_formula) is written only by
 * StonePricingService through StoneService. Quantity is written only by
 * StoneStockService.
 *
 * @property int $quantity
 */
class Stone extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;
    use TracksUserAttribution;

    /**
     * True only while StoneStockService is applying a movement.
     */
    private bool $quantityWriteAuthorized = false;

    /**
     * Quantity is intentionally absent — it is not mass-assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'stone_category_id',
        'stone_code',
        'barcode',
        'name',
        'shape',
        'dimension',
        'carat',
        'pairs',
        'weight_grams',
        'pricing_mode',
        'price_per_carat',
        'price_per_piece',
        'price_per_gram',
        'last_rate',
        'last_rate_mode',
        'rate_changed_at',
        'piece_price',
        'total_cost',
        'cost_formula',
        'status',
        'tray_no',
        'image_code',
        'remarks',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing_mode' => StonePricingMode::class,
            'status' => StoneStatus::class,
            'carat' => 'decimal:3',
            'weight_grams' => 'decimal:3',
            'price_per_carat' => 'decimal:3',
            'price_per_piece' => 'decimal:3',
            'price_per_gram' => 'decimal:3',
            'last_rate' => 'decimal:3',
            'last_rate_mode' => StonePricingMode::class,
            'rate_changed_at' => 'datetime',
            'piece_price' => 'decimal:3',
            'total_cost' => 'decimal:3',
            'quantity' => 'integer',
            'pairs' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Authorize the next quantity assignment. Called only by StoneStockService.
     */
    public function authorizeQuantityWrite(): void
    {
        $this->quantityWriteAuthorized = true;
    }

    /**
     * Guard stones.quantity. Create with 0 is allowed; any other write must
     * be flagged by StoneStockService.
     */
    public function setQuantityAttribute(mixed $value): void
    {
        $int = (int) $value;

        if ($this->quantityWriteAuthorized) {
            $this->attributes['quantity'] = $int;
            $this->quantityWriteAuthorized = false;

            return;
        }

        if (! $this->exists && $int === 0) {
            $this->attributes['quantity'] = 0;

            return;
        }

        throw new RuntimeException('stones.quantity may only be written by StoneStockService.');
    }

    /**
     * Whether this lot is bought and sold by weight. Its stock is the grams in
     * `weight_grams`; `quantity` stays zero, so a sale must ask for grams.
     */
    public function isWeighed(): bool
    {
        return $this->pricing_mode === StonePricingMode::PER_GRAM;
    }

    /**
     * Whether a packet barcode was issued for this lot.
     *
     * stone_barcode_enabled only gates new generation. This is the stored
     * fact used by show/print so a disabled toggle cannot still draw a
     * barcode from stone_code.
     */
    public function hasPacketBarcode(): bool
    {
        return $this->barcode !== null && $this->barcode !== '';
    }

    /**
     * The material folder this lot is filed under, derived from its name.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(StoneCategory::class, 'stone_category_id');
    }

    /**
     * The picture this lot's image code points at, if one exists yet.
     *
     * Matched on the lowercased name inside the same branch (the image
     * model's branch scope applies), so a code never reaches another
     * branch's folder and a missing picture simply yields null.
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(StoneImage::class, 'image_code', 'name_key');
    }

    /**
     * Store the code the way the image folder keys names: trimmed, lowercase,
     * and without an extension, so "Stone1.JPG" and "stone1" are one code.
     */
    public function setImageCodeAttribute(mixed $value): void
    {
        $this->attributes['image_code'] = StoneImage::codeFor($value === null ? null : (string) $value);
    }

    /**
     * Stock ledger rows for this lot.
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StoneStockMovement::class);
    }

    /**
     * Intake line items that referenced this lot.
     */
    public function intakeItems(): HasMany
    {
        return $this->hasMany(StoneIntakeItem::class);
    }

    /**
     * Total carat currently in stock (carat x quantity). Gram lots carry no
     * carat and report zero.
     */
    public function stockCarat(): float
    {
        return round((float) $this->carat * (int) $this->quantity, 3);
    }

    /**
     * Carat for display, e.g. "2.1" rather than the stored "2.100".
     */
    public function caratLabel(): string
    {
        return $this->trimDecimal($this->carat);
    }

    /**
     * Gram weight for display.
     */
    public function gramsLabel(): string
    {
        return $this->trimDecimal($this->weight_grams);
    }

    /**
     * What is physically in the tray right now.
     *
     * Per-gram lots are bought and sold by weight and hold no piece count,
     * so showing "0 pcs" for them would read as out of stock.
     */
    public function onHandLabel(): string
    {
        if ($this->pricing_mode === StonePricingMode::PER_GRAM) {
            return $this->weight_grams === null ? '—' : $this->gramsLabel().' g';
        }

        return $this->quantity.' pcs';
    }

    /**
     * Whether the lot has run out, for the "low stock" styling.
     *
     * Gram lots are never counted in pieces, so an empty quantity on one of
     * them is normal rather than a warning.
     */
    public function isOutOfStock(): bool
    {
        return $this->pricing_mode !== StonePricingMode::PER_GRAM && (int) $this->quantity === 0;
    }

    /**
     * The buying rate that actually applies, ignoring the other two columns.
     */
    public function rate(): ?string
    {
        return match ($this->pricing_mode) {
            StonePricingMode::PER_CARAT => $this->price_per_carat,
            StonePricingMode::PER_PIECE => $this->price_per_piece,
            StonePricingMode::PER_GRAM => $this->price_per_gram,
            default => null,
        };
    }

    /**
     * The rate with its unit, e.g. "Rs 2.00 / ct".
     */
    public function rateLabel(): string
    {
        $rate = $this->rate();

        if ($rate === null) {
            return '—';
        }

        return setting_money($rate).' / '.$this->unitOf($this->pricing_mode);
    }

    /**
     * The price this lot had before it was last changed, with its own unit.
     *
     * The unit is the one the old price was set in, so a lot moved from a
     * per-piece to a per-gram rate still reads correctly. "—" when the price
     * has never been changed.
     */
    public function lastRateLabel(): string
    {
        if ($this->last_rate === null || $this->last_rate_mode === null) {
            return '—';
        }

        return setting_money($this->last_rate).' / '.$this->unitOf($this->last_rate_mode);
    }

    /**
     * Short unit for a pricing mode: ct, pc or g.
     */
    private function unitOf(?StonePricingMode $mode): string
    {
        return match ($mode) {
            StonePricingMode::PER_CARAT => 'ct',
            StonePricingMode::PER_PIECE => 'pc',
            StonePricingMode::PER_GRAM => 'g',
            default => '',
        };
    }

    /**
     * Drop the trailing zeros a decimal cast adds, keeping "—" for nulls.
     */
    private function trimDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $trimmed = rtrim(rtrim((string) $value, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
