<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * LocaleController
 *
 * The language button. Remembers the choice in a cookie (so the login screen
 * keeps it) and, when someone is signed in, on their account so it follows
 * them to any computer.
 */
class LocaleController extends Controller
{
    /**
     * Cookie lifetime: one year, in minutes.
     */
    private const COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * Switch the interface language and return to the same page.
     */
    public function update(Request $request, string $locale): RedirectResponse
    {
        abort_unless(SetLocale::isSupported($locale), 404);

        $user = $request->user();

        if ($user !== null && $user->locale !== $locale) {
            // Only the language changes; this is not an edit worth an audit entry.
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }

        return redirect()
            ->back(fallback: route('login'))
            ->withCookie(cookie(SetLocale::COOKIE, $locale, self::COOKIE_MINUTES));
    }
}
