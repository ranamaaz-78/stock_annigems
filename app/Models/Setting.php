<?php

namespace App\Models;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;

/**
 * Setting
 *
 * One global configuration row. Deliberately has no branch_id and no
 * BelongsToBranch trait: business rules are SuperAdmin-owned and apply to
 * every shop. A future developer adding branch_id here would let a branch
 * invent its own tax rate or discount ceiling and break the design.
 *
 * Keys are fixed in SettingCatalog. This model never accepts a key that the
 * catalog does not list. Reads go through SettingService so callers receive
 * typed values and never query this table themselves.
 *
 * No HasUuid: settings are addressed by catalog key, not a URL.
 */
class Setting extends Model
{
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'group' => SettingGroup::class,
        ];
    }
}
