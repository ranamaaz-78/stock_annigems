<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;

/**
 * StoneImport
 *
 * The record of one Excel import: the file's name, how many rows it held,
 * how many became stones, and how many were set aside. Immutable history —
 * nothing edits it after the import finishes.
 */
class StoneImport extends Model
{
    use BelongsToBranch;
    use HasUuid;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'filename',
        'total_rows',
        'inserted_rows',
        'rejected_rows',
        'chunks_done',
        'rejects_path',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'inserted_rows' => 'integer',
            'rejected_rows' => 'integer',
            'chunks_done' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Whether the import ran to its last step. An import cut short by a
     * closed tab or a lost connection never gets here.
     */
    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /**
     * Whether a rows-to-fix workbook exists for this import.
     */
    public function hasRejects(): bool
    {
        return $this->rejected_rows > 0 && $this->rejects_path !== null;
    }
}
