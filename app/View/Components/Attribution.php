<?php

namespace App\View\Components;

use App\Support\AttributionFormatter;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;

/**
 * Attribution
 *
 * Reusable muted "Created by / Last updated" block for detail screens.
 * Reads the stored role snapshot — never the user's current role.
 *
 * Usage: <x-attribution :record="$branch" />
 */
class Attribution extends Component
{
    /**
     * Pre-formatted created-by line (name, snapshotted role, timestamp).
     */
    public string $createdLine;

    /**
     * Pre-formatted last-updated line, or null when the record was never edited.
     */
    public ?string $updatedLine;

    /**
     * @param  Model  $record  Any model using TracksUserAttribution with standard columns
     */
    public function __construct(public Model $record)
    {
        $this->ensureRelationsLoaded($record);

        $this->createdLine = AttributionFormatter::createdLine($record);
        $this->updatedLine = AttributionFormatter::updatedLine($record);
    }

    /**
     * Load creator/updater including deactivated users, without N+1 on detail pages.
     */
    protected function ensureRelationsLoaded(Model $record): void
    {
        $load = [];

        if (method_exists($record, 'creator') && ! $record->relationLoaded('creator')) {
            $load[] = 'creator';
        }

        if (method_exists($record, 'updater') && ! $record->relationLoaded('updater')) {
            $load[] = 'updater';
        }

        if ($load !== []) {
            $record->load($load);
        }
    }

    /**
     * Render the compact attribution block.
     */
    public function render(): View|Closure|string
    {
        return view('components.attribution');
    }
}
