<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StoneIntakeDraft
 *
 * Server-side working copy of an unsaved scan batch, unique per user+branch.
 * Browser storage is not used: a refresh, closed tab, or device change must
 * still recover the list. Cleared only after a successful intake save.
 *
 * No uuid — addressed by the authenticated user, not a URL.
 */
class StoneIntakeDraft extends Model
{
    use BelongsToBranch;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'user_id',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * User who owns this working batch.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
