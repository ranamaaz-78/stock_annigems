<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * RoleSeeder
 *
 * Seeds all system roles with is_system=true. Idempotent via updateOrCreate on name.
 */
class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'superadmin',
                'display_name' => 'SuperAdmin',
                'description' => 'System-level operator with no branch assignment.',
            ],
            [
                'name' => 'admin',
                'display_name' => 'Admin',
                'description' => 'Full control of their branch including reports.',
            ],
            [
                'name' => 'manager',
                'display_name' => 'Manager',
                'description' => 'Branch operations without reports access.',
            ],
            [
                'name' => 'cashier',
                'display_name' => 'Cashier',
                'description' => 'POS, customers, and invoices only.',
            ],
            [
                'name' => 'inventory_staff',
                'display_name' => 'Inventory Staff',
                'description' => 'Stock, items, and barcode labels.',
            ],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(
                ['name' => $role['name']],
                [
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                    'is_system' => true,
                ]
            );
        }
    }
}
