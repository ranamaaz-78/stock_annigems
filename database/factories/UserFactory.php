<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * UserFactory
 *
 * Default factory state creates an active SuperAdmin-like user without a branch.
 * Tests that need branch users should override branch_id and role explicitly.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Sync role_id from roles table after making a user when roles are seeded.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            if ($user->role_id === null && $user->role instanceof UserRole) {
                $roleId = Role::query()->where('name', $user->role->value)->value('id');
                if ($roleId) {
                    $user->role_id = (int) $roleId;
                }
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= 'password',
            'role' => UserRole::SUPERADMIN,
            'status' => UserStatus::ACTIVE,
            'last_login_at' => null,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Branch Admin on a given (or new) branch.
     */
    public function admin(?int $branchId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::ADMIN,
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Manager on a branch.
     */
    public function manager(?int $branchId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::MANAGER,
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Cashier on a branch.
     */
    public function cashier(?int $branchId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::CASHIER,
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Inventory Staff on a branch.
     */
    public function inventoryStaff(?int $branchId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::INVENTORY_STAFF,
            'branch_id' => $branchId,
        ]);
    }
}
