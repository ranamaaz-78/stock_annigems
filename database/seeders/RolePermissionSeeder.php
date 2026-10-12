<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * RolePermissionSeeder
 *
 * Seeds default role → permission mappings. Module 5 restricts SuperAdmin to
 * system operations + gold rates only (no customers/quotations/invoices).
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissionIds = Permission::query()->pluck('id', 'name');

        foreach ($this->rolePermissionMap() as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $ids = collect($permissionNames)
                ->map(fn (string $name) => $permissionIds[$name] ?? null)
                ->filter()
                ->values()
                ->all();

            $role->permissions()->sync($ids);
        }
    }

    /**
     * Default permission sets per role.
     *
     * @return array<string, list<string>>
     */
    private function rolePermissionMap(): array
    {
        $superadmin = [
            'branches.view',
            'users.view', 'users.create', 'users.edit', 'users.deactivate',
            'gold_rates.view', 'gold_rates.manage',
            // Aggregate cross-branch reporting only. reports.view is deliberately
            // absent: SuperAdmin must never reach branch-level record detail.
            'reports.superadmin',
            'dashboard.view',
            'settings.global.view', 'settings.global.manage', 'settings.history.view',
        ];

        $branchBase = [
            'items.view', 'items.create', 'items.edit', 'items.delete', 'items.view_cost',
            'barcode.generate', 'barcode.print',
            'pos.access', 'pos.discount', 'pos.custom_item',
            'gold_rates.view',
            'customers.view', 'customers.create', 'customers.edit', 'customers.deactivate',
            'quotations.view', 'quotations.create', 'quotations.edit', 'quotations.cancel', 'quotations.convert',
            'performas.view', 'performas.create', 'performas.settle', 'performas.return',
            'creditors.view', 'creditors.create', 'creditors.edit', 'creditors.deactivate',
            'invoices.view', 'invoices.void', 'invoices.discount',
            'delivery_notes.view', 'delivery_notes.create', 'delivery_notes.cancel', 'delivery_notes.convert',
            'repairs.view', 'repairs.create', 'repairs.edit',
            'transfers.view', 'transfers.create',
            'reports.view', 'reports.export',
            'dashboard.view',
            'users.view', 'users.create', 'users.edit', 'users.deactivate',
            'settings.view',
            'settings.global.view',
            'settings.branch.view', 'settings.branch.manage',
            'stones.view', 'stones.create', 'stones.edit', 'stones.deactivate',
            'stones.barcode', 'stones.intake', 'stones.intake_view', 'stones.images',
            'categories.view', 'categories.manage',
            'jewellery.view', 'jewellery.create', 'jewellery.edit', 'jewellery.delete',
            'jewellery.barcode', 'jewellery.status',
        ];

        $manager = array_values(array_diff($branchBase, [
            'invoices.void',
            'customers.deactivate',
            'creditors.deactivate',
            // Reports are Admin-only (spec section 7.2): a Manager sees dashboard
            // statistics but no report screen and no export.
            'reports.view', 'reports.export',
            'users.create', 'users.edit', 'users.deactivate',
            'items.delete',
            'jewellery.delete',
            'stones.deactivate',
            // Branch settings writes are Admin-only (spec section 10).
            'settings.branch.manage',
            // Global settings are SuperAdmin-managed; Admin sees them
            // read-only. Manager, Cashier and Inventory Staff do not.
            'settings.global.view',
        ]));

        $cashier = [
            'pos.access', 'pos.discount', 'pos.custom_item',
            'customers.view', 'customers.create', 'customers.edit',
            'quotations.view', 'quotations.create', 'quotations.edit', 'quotations.convert',
            'performas.view', 'performas.create', 'performas.settle',
            'creditors.view', 'creditors.create', 'creditors.edit',
            'invoices.view', 'invoices.discount',
            'delivery_notes.view', 'delivery_notes.create', 'delivery_notes.convert',
            'items.view',
            'jewellery.view', 'categories.view',
            'dashboard.view',
        ];

        $inventoryStaff = [
            'items.view', 'items.create', 'items.edit',
            'barcode.generate', 'barcode.print',
            'transfers.view', 'transfers.create',
            'repairs.view',
            'stones.view', 'stones.create', 'stones.edit',
            'stones.barcode', 'stones.intake', 'stones.intake_view', 'stones.images',
            'categories.view',
            'jewellery.view', 'jewellery.create', 'jewellery.edit', 'jewellery.barcode', 'jewellery.status',
            'dashboard.view',
        ];

        return [
            'superadmin' => $superadmin,
            'admin' => $branchBase,
            'manager' => $manager,
            'cashier' => $cashier,
            'inventory_staff' => $inventoryStaff,
        ];
    }
}
