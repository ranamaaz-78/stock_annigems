<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\AuthService;
use App\Services\PasswordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * ForceChangePasswordController
 *
 * Shown when must_change_password is true. No other navigation is available
 * until a strong password is set.
 */
class ForceChangePasswordController extends Controller
{
    public function __construct(
        protected PasswordService $passwordService,
        protected AuthService $authService
    ) {}

    /**
     * Show the forced change-password form.
     */
    public function edit(): View
    {
        return view('auth.change-password');
    }

    /**
     * Persist the new password and continue to the role dashboard.
     */
    public function update(ChangePasswordRequest $request): RedirectResponse
    {
        $user = $this->passwordService->changePassword($request->user(), $request->validated());

        return redirect()
            ->route($this->authService->redirectRouteFor($user))
            ->with('success', __('Password updated successfully.'));
    }
}
