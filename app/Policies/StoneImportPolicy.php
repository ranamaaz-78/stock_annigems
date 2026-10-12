<?php

namespace App\Policies;

use App\Models\StoneImport;
use App\Models\User;
use App\Policies\Concerns\DeniesSuperAdminRecordAccess;

/**
 * StoneImportPolicy
 *
 * Import history belongs to the branch that ran the import. Whoever may
 * import stones may read what their imports did and fetch the rows that
 * were set aside, so no separate permission is needed.
 */
class StoneImportPolicy
{
    use DeniesSuperAdminRecordAccess;

    /**
     * Open the import history.
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.create');
    }

    /**
     * Fetch the rows-to-fix workbook of an import in the actor's branch.
     */
    public function view(User $user, StoneImport $import): bool
    {
        return $this->mayReachRecords($user)
            && $user->hasPermission('stones.create')
            && (int) $user->branch_id === (int) $import->branch_id;
    }
}
