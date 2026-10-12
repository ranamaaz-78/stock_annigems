<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureBranchMember
 *
 * Blocks SuperAdmin and users without a branch from branch-scoped routes.
 * Branch operations require an assigned branch_id on the authenticated user.
 */
class EnsureBranchMember
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isSuperAdmin() || $user->branch_id === null) {
            abort(403);
        }

        return $next($request);
    }
}
