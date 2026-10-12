<?php

namespace App\Services;

use App\Models\StoneIntakeDraft;
use App\Models\User;

/**
 * StoneIntakeDraftService
 *
 * Persists the in-progress scan list on the server so a refresh, closed tab,
 * or dropped connection does not lose a large batch. One draft per user+branch.
 */
class StoneIntakeDraftService
{
    /**
     * Current draft payload, or null when the operator has no unsaved batch.
     *
     * @return array<string, mixed>|null
     */
    public function current(User $actor): ?array
    {
        $draft = StoneIntakeDraft::query()
            ->where('user_id', $actor->id)
            ->first();

        return $draft?->payload;
    }

    /**
     * Upsert the working list after every change (debounced by the client).
     *
     * @param  array<string, mixed>  $payload
     */
    public function save(User $actor, array $payload): StoneIntakeDraft
    {
        return StoneIntakeDraft::query()->updateOrCreate(
            [
                'branch_id' => $actor->branch_id,
                'user_id' => $actor->id,
            ],
            [
                'payload' => $payload,
            ]
        );
    }

    /**
     * Remove the draft after a successful save or an explicit discard.
     */
    public function discard(User $actor): void
    {
        StoneIntakeDraft::query()
            ->where('user_id', $actor->id)
            ->delete();
    }
}
