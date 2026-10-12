<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * CategoryPolicy
 *
 * Global catalog. Any branch user with the permission may view or manage;
 * changes are visible to every branch.
 */
class CategoryPolicy
{
    /**
     * Open the categories screen.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('categories.view') || $user->hasPermission('categories.manage');
    }

    /**
     * View one category.
     */
    public function view(User $user, Category $category): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Create / update / delete / deactivate.
     */
    public function manage(User $user): bool
    {
        return $user->hasPermission('categories.manage');
    }
}
