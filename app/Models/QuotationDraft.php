<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * QuotationDraft
 *
 * Server-side autosave for the in-progress quotation workspace. One draft per
 * user+branch so a refresh does not lose work.
 */
class QuotationDraft extends Model
{
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
     * Branch context for this draft.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * User who owns this draft.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
