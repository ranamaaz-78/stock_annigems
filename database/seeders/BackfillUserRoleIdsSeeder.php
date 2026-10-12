<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * BackfillUserRoleIdsSeeder
 *
 * Links existing users' role_id FK from the role enum mirror. Idempotent.
 */
class BackfillUserRoleIdsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rolesByName = Role::query()->pluck('id', 'name');

        User::query()->withTrashed()->whereNull('role_id')->each(function (User $user) use ($rolesByName): void {
            $roleName = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;
            $roleId = $rolesByName[$roleName] ?? null;

            if ($roleId) {
                $user->forceFill(['role_id' => $roleId])->saveQuietly();
            }
        });
    }
}
