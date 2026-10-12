<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ProfileService
 *
 * Self-service profile updates for branch users. Cannot change role,
 * permissions, branch, or status — those are Admin-only operations.
 */
class ProfileService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected PasswordService $passwordService
    ) {}

    /**
     * Update the authenticated user's basic profile fields.
     *
     * @param  array{name: string, phone?: string|null, new_password?: string}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $old = [
                'name' => $user->name,
                'phone' => $user->phone,
            ];

            $updates = [
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
            ];

            if (! empty($data['new_password'])) {
                $updates['password'] = $data['new_password'];
                $updates['must_change_password'] = false;
            }

            $user->forceFill($updates)->save();

            $this->auditLogService->log(
                'user.profile_updated',
                $user,
                $old,
                [
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'password_changed' => ! empty($data['new_password']),
                ],
                $user
            );

            return $user->fresh();
        });
    }
}
