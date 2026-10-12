<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureSuperAdmin
 *
 * Restricts routes to authenticated users with UserRole::SUPERADMIN.
 * Returns 403 for everyone else (UI menu hiding is not sufficient).
 */
class EnsureSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== UserRole::SUPERADMIN) {
            abort(403);
        }

        return $next($request);
    }
}
