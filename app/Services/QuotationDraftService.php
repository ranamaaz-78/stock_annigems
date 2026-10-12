<?php

namespace App\Services;

use App\Models\QuotationDraft;
use App\Models\User;

/**
 * QuotationDraftService
 *
 * Server-side autosave for the quotation workspace. One draft per user+branch.
 */
class QuotationDraftService
{
    /**
     * @return array<string, mixed>|null
     */
    public function current(User $actor): ?array
    {
        $draft = QuotationDraft::query()
            ->where('branch_id', $actor->branch_id)
            ->where('user_id', $actor->id)
            ->first();

        return $draft?->payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(User $actor, array $payload, ?int $quotationId = null): QuotationDraft
    {
        $payload['quotation_id'] = $quotationId;

        return QuotationDraft::query()->updateOrCreate(
            [
                'branch_id' => $actor->branch_id,
                'user_id' => $actor->id,
            ],
            ['payload' => $payload]
        );
    }

    /**
     * Discard draft after successful save or explicit clear.
     */
    public function discard(User $actor): void
    {
        QuotationDraft::query()
            ->where('branch_id', $actor->branch_id)
            ->where('user_id', $actor->id)
            ->delete();
    }
}
