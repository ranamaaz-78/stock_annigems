<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TaxRate
 *
 * One selectable rate: an IVA rate (including an exempt, 0% one) or a
 * Recargo de equivalencia rate. Managed by SuperAdmin; documents keep a
 * snapshot of what they used, so a rate can change without touching them.
 */
class TaxRate extends Model
{
    use HasUuid;
    use SoftDeletes;

    public const KIND_IVA = 'iva';

    public const KIND_RECARGO = 'recargo';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'name',
        'rate',
        'is_exempt',
        'is_default',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:3',
            'is_exempt' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Rates of one kind, in the order SuperAdmin arranged them.
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind)->orderBy('sort_order')->orderBy('rate', 'desc')->orderBy('id');
    }

    /**
     * Rates that may be chosen on a new document.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Rate trimmed of trailing zeros, e.g. "21" or "5.2".
     */
    public function rateLabel(): string
    {
        $trimmed = rtrim(rtrim(number_format((float) $this->rate, 3, '.', ''), '0'), '.');

        return ($trimmed === '' ? '0' : $trimmed).'%';
    }
}
