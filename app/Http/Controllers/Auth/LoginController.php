<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LoginController
 *
 * HTTP layer for the shared login screen used by all roles.
 */
class LoginController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * Show the login form.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Authenticate the user and redirect by role / password-change flag.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $this->authService->login($request->validated(), $request);

        return redirect()->route($this->authService->redirectRouteFor($user));
    }

    /**
     * Log the user out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->authService->logout($request);

        return redirect()->route('login');
    }
}
