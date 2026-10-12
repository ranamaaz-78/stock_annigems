<?php

namespace App\Http\Middleware;

use App\Support\Settings\RuntimeSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApplySettingsSession
 *
 * Idle timeout follows config('session.lifetime') (SESSION_LIFETIME), not
 * a SuperAdmin setting:
 *
 * 1. Tracks last activity on the session. When the idle gap exceeds the
 *    timeout, the user is logged out and sent to the session-expired page
 *    built in Module 1 — not silently dumped on the login form.
 *
 * Public and guest routes skip the idle check.
 */
class ApplySettingsSession
{
    /**
     * Apply the configured idle timeout.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Overlay timezone, brand and currency so every Blade / service
        // config() read sees the live SuperAdmin bag.
        RuntimeSettings::apply();

        $minutes = max(5, min(1440, (int) config('session.lifetime', 120)));

        if (! Auth::check()) {
            return $next($request);
        }

        $last = $request->session()->get('settings.last_activity');
        $now = time();

        if (is_int($last) && ($now - $last) > ($minutes * 60)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('session.expired');
        }

        $request->session()->put('settings.last_activity', $now);

        return $next($request);
    }
}
