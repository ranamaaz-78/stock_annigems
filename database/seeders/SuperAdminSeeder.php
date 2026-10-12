<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * SuperAdminSeeder
 *
 * Creates the first (and only bootstrap) SuperAdmin account from .env.
 * Idempotent: running twice updates the existing account by email instead
 * of inserting a duplicate. No dummy branches or other users are seeded.
 */
class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @throws RuntimeException when SUPERADMIN_EMAIL or SUPERADMIN_PASSWORD is missing
     */
    public function run(): void
    {
        $name = (string) config('annigems.superadmin.name', 'Super Admin');
        $email = config('annigems.superadmin.email');
        $password = config('annigems.superadmin.password');

        // Credentials must come from environment — never hardcode secrets here.
        if (empty($email) || empty($password)) {
            throw new RuntimeException(
                'SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD must be set in the .env file before seeding.'
            );
        }

        $superadminRole = Role::query()->where('name', UserRole::SUPERADMIN->value)->first();

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'branch_id' => null,
                // Plain password — User model casts password to hashed.
                'password' => $password,
                'role' => UserRole::SUPERADMIN,
                'role_id' => $superadminRole?->id,
                'status' => UserStatus::ACTIVE,
                // Force the operator to set their own password on first login.
                'must_change_password' => true,
            ]
        );
    }
}
