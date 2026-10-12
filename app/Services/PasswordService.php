<?php

namespace App\Services;

use App\Models\User;
use App\Support\Settings\PasswordRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * PasswordService
 *
 * Forced first-login password change and forgot-password reset flows.
 * Always writes an audit entry when a password is changed by the user.
 */
class PasswordService
{
    public function __construct(
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Set a new password for the authenticated user and clear must_change_password.
     *
     * @param  array{password: string}  $data
     */
    public function changePassword(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $user->forceFill([
                'password' => $data['password'],
                'must_change_password' => false,
            ])->save();

            $this->auditLogService->log(
                'user.password_changed',
                $user,
                null,
                ['must_change_password' => false],
                $user
            );

            return $user->fresh();
        });
    }

    /**
     * Send a password reset link to the given email (Laravel broker).
     *
     * @return string  Password broker status constant
     */
    public function sendResetLink(string $email): string
    {
        return Password::sendResetLink(['email' => $email]);
    }

    /**
     * Reset password using a valid token from the email link.
     *
     * @param  array{email: string, password: string, password_confirmation: string, token: string}  $data
     * @return string  Password broker status constant
     */
    public function resetPassword(array $data): string
    {
        return Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();

                $this->auditLogService->log(
                    'user.password_reset',
                    $user,
                    null,
                    ['via' => 'forgot_password'],
                    $user
                );
            }
        );
    }

    /**
     * Generate a cryptographically random temporary password.
     */
    public function generateTemporaryPassword(int $length = 12): string
    {
        $min = max($length, PasswordRules::minLength());

        // Generated temps must satisfy the live password rule so first-login
        // change-password is the only extra step, not a second validation fail.
        return Str::password($min, letters: true, numbers: true, symbols: false, spaces: false);
    }

    /**
     * Verify a plain password against the user's hash (for sensitive confirmations).
     */
    public function check(User $user, string $plain): bool
    {
        return Hash::check($plain, $user->password);
    }
}
