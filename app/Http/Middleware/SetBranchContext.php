<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetBranchContext
 *
 * Attaches the authenticated user's branch_id to the request attributes so
 * future modules can scope queries without reading Auth in every layer.
 * SuperAdmin has no branch — branch_id remains null and bypasses scoping.
 */
class SetBranchContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $request->attributes->set('branch_id', $user?->branch_id);
        $request->attributes->set('is_superadmin', (bool) $user?->isSuperAdmin());

        return $next($request);
    }
}
