<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuthService;
use App\Services\LoginAsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * LoginAsController
 *
 * "Log in as" for Super Admins: one click on the users list signs in as that
 * account, as a normal login. Super Admin routes only.
 */
class LoginAsController extends Controller
{
    public function __construct(
        private LoginAsService $loginAsService,
        private AuthService $authService,
    ) {}

    /**
     * Sign in as the chosen user.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        $this->authorize('loginAs', $user);

        $reason = $this->loginAsService->refusalFor($request->user(), $user);
        if ($reason !== null) {
            return back()->with('error', $reason);
        }

        $this->loginAsService->loginAs($request->user(), $user, $request);

        // Same landing as a real login, including a pending password change.
        return redirect()->route($this->authService->redirectRouteFor($user));
    }
}
