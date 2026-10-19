<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AuthService
 *
 * Handles login, logout, last_login_at updates, inactive user/branch checks,
 * and role-based post-login redirects. Controllers must stay thin and call here.
 */
class AuthService
{
    /**
     * Attempt to authenticate a user with email/password.
     *
     * Updates last_login_at on success. Throws ValidationException with a
     * generic message on bad credentials (never reveals which field failed).
     *
     * @param  array{email: string, password: string, remember?: bool}  $credentials
     * @return User The authenticated user
     *
     * @throws ValidationException
     */
    public function login(array $credentials, Request $request): User
    {
        $this->ensureIsNotRateLimited($request);

        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ], $remember)) {
            RateLimiter::hit($this->throttleKey($request), $this->lockoutSeconds());

            throw ValidationException::withMessages([
                'email' => __('Invalid email or password.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        /** @var User $user */
        $user = Auth::user();

        // Soft-deleted users should not reach here, but guard anyway.
        if ($user->trashed()) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => __('Invalid email or password.'),
            ]);
        }

        $this->assertAccountMayLogin($user);

        // saveQuietly skips attribution events so a login does not look like
        // an edit of the user record (updated_by would otherwise become self).
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $request->session()->regenerate();

        return $user->fresh();
    }

    /**
     * Log the current user out and invalidate the session.
     */
    public function logout(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Named route users should land on after a successful login (or password change).
     */
    public function redirectRouteFor(User $user): string
    {
        if ($user->must_change_password) {
            return 'password.change';
        }

        return match ($user->role) {
            UserRole::SUPERADMIN => 'superadmin.dashboard',
            default => 'branch.dashboard',
        };
    }

    /**
     * Block inactive users and users whose branch is inactive.
     *
     * @throws ValidationException
     */
    public function assertAccountMayLogin(User $user): void
    {
        if ($user->status !== UserStatus::ACTIVE) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => __('Your account is inactive. Please contact your administrator.'),
            ]);
        }

        // SuperAdmin has no branch; skip branch status check.
        if ($user->isSuperAdmin()) {
            return;
        }

        $branch = $user->branch;
        if (! $branch || ! $branch->isActive()) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => __('Your account is inactive. Please contact your administrator.'),
            ]);
        }
    }

    /**
     * Ensure the login endpoint has not exceeded the configured attempt limit.
     *
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(Request $request): void
    {
        $maxAttempts = (int) config('annigems.login_max_attempts', 5);

        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), $maxAttempts)) {
            return;
        }

        abort(429);
    }

    /**
     * Throttle key scoped by email + IP so attackers cannot share a global bucket easily.
     */
    protected function throttleKey(Request $request): string
    {
        return Str::lower((string) $request->input('email')).'|'.$request->ip();
    }

    /**
     * Lockout window from config (seconds).
     */
    protected function lockoutSeconds(): int
    {
        return max(60, (int) config('annigems.login_decay_seconds', 900));
    }
}
