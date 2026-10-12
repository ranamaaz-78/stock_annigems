<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * LoginAsService
 *
 * A Super Admin opens a branch account with one click, without its
 * password. It is an ordinary login: the Super Admin session ends and the
 * chosen account is signed in on a fresh session, exactly as if its owner had
 * logged in. There is no way back to the Super Admin from inside that
 * account; logging out lands on the login page, like any other user.
 *
 * Each use is written to the audit log under the Super Admin's name.
 */
class LoginAsService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * Why this account cannot be opened, or null when it can.
     *
     * The operator is told the reason instead of seeing a bare "forbidden".
     */
    public function refusalFor(User $admin, User $target): ?string
    {
        if (! $admin->isSuperAdmin()) {
            return 'Only a Super Admin can log in as another user.';
        }

        if ($admin->id === $target->id || $target->isSuperAdmin()) {
            return 'You cannot log in as a Super Admin account.';
        }

        if ($target->trashed() || $target->status !== UserStatus::ACTIVE) {
            return 'This account is inactive. Activate it first.';
        }

        if ($target->branch === null || ! $target->branch->isActive()) {
            return 'This account belongs to an inactive branch.';
        }

        return null;
    }

    /**
     * End the Super Admin session and sign the target account in.
     */
    public function loginAs(User $admin, User $target, Request $request): void
    {
        // Recorded first, while the Super Admin is still the signed-in user.
        $this->auditLogService->log('user.admin_login_as', $target, null, [
            'logged_in_user' => $target->email,
            'logged_in_role' => $target->role->value,
        ], $admin);

        // A clean cut: the Super Admin's session is destroyed, not carried over.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Auth::login($target);
        $request->session()->regenerate();

        // saveQuietly: a login is not an edit of the user record.
        $target->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
