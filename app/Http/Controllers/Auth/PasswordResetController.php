<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * PasswordResetController
 *
 * Forgot-password request and token reset screens (on-brand guest layout).
 */
class PasswordResetController extends Controller
{
    public function __construct(
        protected PasswordService $passwordService
    ) {}

    /**
     * Show the forgot-password form.
     */
    public function requestForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Email a reset link when the address exists (generic success either way).
     */
    public function sendLink(ForgotPasswordRequest $request): RedirectResponse
    {
        $status = $this->passwordService->sendResetLink($request->validated('email'));

        // Always show a neutral success message to avoid account enumeration.
        return back()->with(
            'success',
            __('If that email is registered, a password reset link has been sent.')
        )->with('status', $status);
    }

    /**
     * Show the reset form from the emailed token link.
     */
    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Complete the password reset.
     */
    public function reset(ResetPasswordRequest $request): RedirectResponse
    {
        $status = $this->passwordService->resetPassword($request->validated());

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', __('Password reset successfully. You can log in now.'));
        }

        return back()->withErrors(['email' => __($status)]);
    }
}
