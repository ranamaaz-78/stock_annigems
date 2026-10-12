<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * PermissionSeeder
 *
 * Seeds every discrete module.action permission. Idempotent via updateOrCreate on name.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->definitions() as $permission) {
            Permission::query()->updateOrCreate(
                ['name' => $permission['name']],
                [
                    'module' => $permission['module'],
                    'display_name' => $permission['display_name'],
                    'description' => $permission['description'] ?? null,
                ]
            );
        }
    }

    /**
     * Full permission catalogue for Module 2 (more added in later modules).
     *
     * @return list<array{name: string, module: string, display_name: string, description?: string}>
     */
    private function definitions(): array
    {
        return [
            // Branches (SuperAdmin / read-only Admin)
            ['module' => 'branches', 'name' => 'branches.view', 'display_name' => 'View Branches'],

            // Users
            ['module' => 'users', 'name' => 'users.view', 'display_name' => 'View Users'],
            ['module' => 'users', 'name' => 'users.create', 'display_name' => 'Create Users'],
            ['module' => 'users', 'name' => 'users.edit', 'display_name' => 'Edit Users'],
            ['module' => 'users', 'name' => 'users.deactivate', 'display_name' => 'Deactivate Users'],

            // Items
            ['module' => 'items', 'name' => 'items.view', 'display_name' => 'View Items'],
            ['module' => 'items', 'name' => 'items.create', 'display_name' => 'Create Items'],
            ['module' => 'items', 'name' => 'items.edit', 'display_name' => 'Edit Items'],
            ['module' => 'items', 'name' => 'items.delete', 'display_name' => 'Delete Items'],
            ['module' => 'items', 'name' => 'items.view_cost', 'display_name' => 'View Purchase Cost', 'description' => 'Restricted to Admin and Manager'],

            // Barcode
            ['module' => 'barcode', 'name' => 'barcode.generate', 'display_name' => 'Generate Barcodes'],
            ['module' => 'barcode', 'name' => 'barcode.print', 'display_name' => 'Print Labels'],

            // POS
            ['module' => 'pos', 'name' => 'pos.access', 'display_name' => 'Access POS'],
            ['module' => 'pos', 'name' => 'pos.discount', 'display_name' => 'Apply Discounts'],
            ['module' => 'pos', 'name' => 'pos.custom_item', 'display_name' => 'Custom POS Items'],

            // Invoices
            ['module' => 'invoices', 'name' => 'invoices.view', 'display_name' => 'View Invoices'],
            ['module' => 'invoices', 'name' => 'invoices.void', 'display_name' => 'Void Invoices'],
            ['module' => 'invoices', 'name' => 'invoices.discount', 'display_name' => 'Apply Invoice Discounts'],

            // Gold rates (SuperAdmin manage; Admin read-only)
            ['module' => 'gold_rates', 'name' => 'gold_rates.view', 'display_name' => 'View Gold Rates'],
            ['module' => 'gold_rates', 'name' => 'gold_rates.manage', 'display_name' => 'Manage Gold Rates', 'description' => 'SuperAdmin only — hard-gated'],

            // Quotations
            ['module' => 'quotations', 'name' => 'quotations.view', 'display_name' => 'View Quotations'],
            ['module' => 'quotations', 'name' => 'quotations.create', 'display_name' => 'Create Quotations'],
            ['module' => 'quotations', 'name' => 'quotations.edit', 'display_name' => 'Edit Quotations'],
            ['module' => 'quotations', 'name' => 'quotations.cancel', 'display_name' => 'Cancel Quotations'],
            ['module' => 'quotations', 'name' => 'quotations.convert', 'display_name' => 'Convert Quotations to Invoice'],

            // Performas (credit issue + settlement)
            ['module' => 'performas', 'name' => 'performas.view', 'display_name' => 'View Performas'],
            ['module' => 'performas', 'name' => 'performas.create', 'display_name' => 'Issue Performas'],
            ['module' => 'performas', 'name' => 'performas.settle', 'display_name' => 'Settle Performas'],
            ['module' => 'performas', 'name' => 'performas.return', 'display_name' => 'Return Unpaid Performa Goods'],

            // Customers
            ['module' => 'creditors', 'name' => 'creditors.view', 'display_name' => 'View Creditors'],
            ['module' => 'creditors', 'name' => 'creditors.create', 'display_name' => 'Create Creditors'],
            ['module' => 'creditors', 'name' => 'creditors.edit', 'display_name' => 'Edit Creditors'],
            ['module' => 'creditors', 'name' => 'creditors.deactivate', 'display_name' => 'Deactivate Creditors'],

            ['module' => 'customers', 'name' => 'customers.view', 'display_name' => 'View Customers'],
            ['module' => 'customers', 'name' => 'customers.create', 'display_name' => 'Create Customers'],
            ['module' => 'customers', 'name' => 'customers.edit', 'display_name' => 'Edit Customers'],
            ['module' => 'customers', 'name' => 'customers.deactivate', 'display_name' => 'Deactivate Customers'],

            // Repairs
            ['module' => 'repairs', 'name' => 'repairs.view', 'display_name' => 'View Repairs'],
            ['module' => 'repairs', 'name' => 'repairs.create', 'display_name' => 'Create Repairs'],
            ['module' => 'repairs', 'name' => 'repairs.edit', 'display_name' => 'Edit Repairs'],

            // Transfers
            ['module' => 'transfers', 'name' => 'transfers.view', 'display_name' => 'View Transfers'],
            ['module' => 'transfers', 'name' => 'transfers.create', 'display_name' => 'Create Transfers'],

            // Reports (Module 6). reports.view / reports.export are Admin-only
            // hard gates; reports.superadmin is the SuperAdmin aggregate set and
            // never grants access to an individual record.
            ['module' => 'reports', 'name' => 'reports.view', 'display_name' => 'View Reports', 'description' => 'Admin only — hard-gated'],
            ['module' => 'reports', 'name' => 'reports.export', 'display_name' => 'Export Reports', 'description' => 'Admin only — hard-gated'],
            ['module' => 'reports', 'name' => 'reports.superadmin', 'display_name' => 'View Cross-Branch Reports', 'description' => 'SuperAdmin only — aggregate figures, never records'],

            // Dashboard (every role; card content varies by role)
            ['module' => 'dashboard', 'name' => 'dashboard.view', 'display_name' => 'View Dashboard'],

            // Settings (Module 7). Global writes are SuperAdmin-only; branch
            // writes are Admin-only. settings.view is kept so existing role
            // rows do not break on re-seed before RolePermissionSeeder runs.
            ['module' => 'settings', 'name' => 'settings.view', 'display_name' => 'View Settings', 'description' => 'Legacy alias — replaced by the granular settings.* permissions'],
            ['module' => 'settings', 'name' => 'settings.global.view', 'display_name' => 'View Global Settings', 'description' => 'SuperAdmin full; Admin read-only'],
            ['module' => 'settings', 'name' => 'settings.global.manage', 'display_name' => 'Manage Global Settings', 'description' => 'SuperAdmin only — hard-gated'],
            ['module' => 'settings', 'name' => 'settings.history.view', 'display_name' => 'View Settings History', 'description' => 'SuperAdmin only — hard-gated'],
            ['module' => 'settings', 'name' => 'settings.branch.view', 'display_name' => 'View Branch Settings'],
            ['module' => 'settings', 'name' => 'settings.branch.manage', 'display_name' => 'Manage Branch Settings', 'description' => 'Admin only — hard-gated'],

            // Stones (quantity-based packet inventory)
            ['module' => 'stones', 'name' => 'stones.view', 'display_name' => 'View Stones'],
            ['module' => 'stones', 'name' => 'stones.create', 'display_name' => 'Register Stones'],
            ['module' => 'stones', 'name' => 'stones.edit', 'display_name' => 'Edit Stones'],
            ['module' => 'stones', 'name' => 'stones.deactivate', 'display_name' => 'Deactivate Stones'],
            ['module' => 'stones', 'name' => 'stones.barcode', 'display_name' => 'Print Stone Labels'],
            ['module' => 'stones', 'name' => 'stones.intake', 'display_name' => 'Perform Stone Intake'],
            ['module' => 'stones', 'name' => 'stones.intake_view', 'display_name' => 'View Stone Intake History'],
            ['module' => 'stones', 'name' => 'stones.images', 'display_name' => 'Manage Stone Images', 'description' => 'Branch image folder under Stones: upload, preview, and rename'],

            // Categories (global jewellery catalog)
            ['module' => 'categories', 'name' => 'categories.view', 'display_name' => 'View Categories'],
            ['module' => 'categories', 'name' => 'categories.manage', 'display_name' => 'Manage Categories'],

            // Jewellery pieces (one row per physical item; no cost permission)
            ['module' => 'jewellery', 'name' => 'jewellery.view', 'display_name' => 'View Jewellery'],
            ['module' => 'jewellery', 'name' => 'jewellery.create', 'display_name' => 'Create Jewellery'],
            ['module' => 'jewellery', 'name' => 'jewellery.edit', 'display_name' => 'Edit Jewellery'],
            ['module' => 'jewellery', 'name' => 'jewellery.delete', 'display_name' => 'Delete Jewellery'],
            ['module' => 'jewellery', 'name' => 'jewellery.barcode', 'display_name' => 'Print Jewellery Labels'],
            ['module' => 'jewellery', 'name' => 'jewellery.status', 'display_name' => 'Change Jewellery Status'],
        ];
    }
}
