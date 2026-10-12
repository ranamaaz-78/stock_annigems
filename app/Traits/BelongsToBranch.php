<?php

namespace App\Traits;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsToBranch
 *
 * Apply to every future branch-owned model (items, invoices, customers, etc.).
 * Automatically filters queries to the authenticated user's branch.
 * SuperAdmin bypasses the global scope so they can see cross-branch data
 * where policies explicitly allow it.
 *
 * Scoping is always by branch_id — never by created_by. Attribution is
 * not ownership; a replacement Admin must see every record on the branch.
 */
trait BelongsToBranch
{
    /**
     * Boot the trait: register the global BranchScope.
     */
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        // When creating records as a branch user, default branch_id from auth context.
        static::creating(function ($model): void {
            $user = auth()->user();
            if ($user && $user->role !== UserRole::SUPERADMIN && empty($model->branch_id)) {
                $model->branch_id = $user->branch_id;
            }
        });
    }

    /**
     * Owning branch relationship.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
