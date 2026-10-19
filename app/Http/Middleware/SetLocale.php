<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetLocale
 *
 * Picks the interface language for the request, in this order:
 *
 * 1. the signed-in user's saved choice (users.locale),
 * 2. the browser's `locale` cookie (covers the login screen),
 * 3. the app default (config app.locale).
 *
 * Only languages listed in config app.supported_locales are honoured, so a
 * hand-edited cookie can never select a language the app has no text for.
 * Carbon follows too, so month and day names on dates match the screen.
 */
class SetLocale
{
    /**
     * Cookie that remembers the choice for guests and across logins.
     */
    public const COOKIE = 'locale';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::resolve($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    /**
     * The language this request should use.
     */
    public static function resolve(Request $request): string
    {
        foreach ([$request->user()?->locale, $request->cookie(self::COOKIE)] as $candidate) {
            if (self::isSupported($candidate)) {
                return $candidate;
            }
        }

        return (string) config('app.locale', 'es');
    }

    /**
     * Whether a value names a language the app ships text for.
     */
    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, (array) config('app.supported_locales', []));
    }
}
