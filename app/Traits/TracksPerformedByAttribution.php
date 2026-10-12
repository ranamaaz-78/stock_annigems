<?php

namespace App\Traits;

/**
 * TracksPerformedByAttribution
 *
 * Column-map override for immutable action records (stone intakes, stock
 * movements) that store performed_by + performed_by_role instead of
 * created_by / updated_by. Use together with TracksUserAttribution — this
 * trait only remaps the column names; the central trait still writes the
 * role snapshot at the moment of the action.
 */
trait TracksPerformedByAttribution
{
    /**
     * Map generic attribution onto performed_by columns. updated_* is null
     * because these rows are never edited after insert.
     *
     * @return array{created_by: ?string, created_by_role: ?string, updated_by: ?string, updated_by_role: ?string}
     */
    public function attributionColumnMap(): array
    {
        return [
            'created_by' => 'performed_by',
            'created_by_role' => 'performed_by_role',
            'updated_by' => null,
            'updated_by_role' => null,
        ];
    }
}
