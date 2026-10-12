<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePasswordChanged
 *
 * When must_change_password is true, the user may only access the change-password
 * routes (and logout). Everything else redirects to the force-change screen.
 */
class EnsurePasswordChanged
{
    /**
     * Route names that remain reachable while a password change is required.
     *
     * @var list<string>
     */
    protected array $allowed = [
        'password.change',
        'password.change.update',
        'logout',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            if (! $request->routeIs($this->allowed)) {
                return redirect()->route('password.change');
            }
        }

        return $next($request);
    }
}
