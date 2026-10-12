<?php

namespace App\Models;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BranchSetting
 *
 * A physically local preference for one shop: label stock, printer format,
 * document footer. Contact details are NOT stored here — they live on the
 * Branch record so an invoice cannot print a stale copy of the address.
 *
 * No BelongsToBranch global scope: SettingService / BranchSettingService
 * apply branch_id explicitly, matching the report repositories.
 */
class BranchSetting extends Model
{
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'key',
        'value',
        'type',
        'group',
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

    /**
     * Branch this preference belongs to.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
