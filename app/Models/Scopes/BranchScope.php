<?php

namespace App\Models\Scopes;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * BranchScope
 *
 * Global scope that restricts queries to the current user's branch_id.
 * SuperAdmin and unauthenticated console contexts are not filtered.
 *
 * Special case — User model: this scope must NOT call auth()->user() blindly
 * while the session guard is still loading the User record, or PHP/Xdebug
 * hits infinite recursion (scope → auth()->user() → User query → scope…).
 * A re-entrancy guard skips scoping during that bootstrap query; once the
 * authenticated user is cached on the guard, normal branch filtering applies.
 */
class BranchScope implements Scope
{
    /**
     * True while the session guard is loading the User from the database.
     */
    private static bool $resolvingAuthenticatedUser = false;

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = $this->resolveAuthenticatedUser();

        // No auth (seeders, queues, auth bootstrap inner query) or SuperAdmin.
        if (! $user || $user->role === UserRole::SUPERADMIN) {
            return;
        }

        if ($user->branch_id === null) {
            // Non-superadmin without a branch should see nothing.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.branch_id', $user->branch_id);
    }

    /**
     * Resolve the authenticated user without re-entering this scope during login.
     */
    private function resolveAuthenticatedUser(): ?User
    {
        if (self::$resolvingAuthenticatedUser) {
            return null;
        }

        self::$resolvingAuthenticatedUser = true;

        try {
            $user = auth()->user();

            return $user instanceof User ? $user : null;
        } finally {
            self::$resolvingAuthenticatedUser = false;
        }
    }
}
