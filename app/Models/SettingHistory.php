<?php

namespace App\Models;

use App\Enums\SettingScope;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SettingHistory
 *
 * One row per settings change. The Settings Change History screen reads
 * this table — "who turned the tax off last Tuesday, and which role they
 * held". AuditLogService writes a parallel entry; that log is the system
 * trail, this table is the operator-facing one.
 *
 * Insert-only. A history row is never edited.
 */
class SettingHistory extends Model
{
    use TracksUserAttribution;

    /**
     * The operator-facing history table. Laravel would otherwise look for
     * setting_histories.
     */
    protected $table = 'settings_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scope',
        'branch_id',
        'key',
        'old_value',
        'new_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => SettingScope::class,
        ];
    }

    /**
     * History is written once. Only the change attribution is stored.
     *
     * @return array{created_by: ?string, created_by_role: ?string, updated_by: ?string, updated_by_role: ?string}
     */
    public function attributionColumnMap(): array
    {
        return [
            'created_by' => 'changed_by',
            'created_by_role' => 'changed_by_role',
            'updated_by' => null,
            'updated_by_role' => null,
        ];
    }

    /**
     * Branch the change applied to, when scope is branch.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Actor who made the change, including deactivated users.
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by')->withTrashed();
    }
}
