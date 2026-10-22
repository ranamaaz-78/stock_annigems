<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Soft-delete and Trash permissions for databases seeded before the feature.
 *
 * Attached to the roles that already hold the matching destructive permission
 * (Admin), so the buttons and the Trash menu show after migrate without
 * re-running RolePermissionSeeder.
 */
return new class extends Migration
{
    /**
     * permission => [module, display name, sibling permission that decides who gets it]
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const PERMISSIONS = [
        'invoices.delete' => ['invoices', 'Delete Invoices', 'invoices.void'],
        'quotations.delete' => ['quotations', 'Delete Quotations', 'invoices.void'],
        'performas.delete' => ['performas', 'Delete Performas', 'invoices.void'],
        'delivery_notes.delete' => ['delivery_notes', 'Delete Delivery Notes', 'invoices.void'],
        'customers.delete' => ['customers', 'Delete Customers', 'invoices.void'],
        'creditors.delete' => ['creditors', 'Delete Creditors', 'invoices.void'],
        'trash.view' => ['trash', 'View and Restore Trash', 'invoices.void'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name => [$module, $display, $sibling]) {
            $id = Permission::query()->updateOrCreate(
                ['name' => $name],
                ['module' => $module, 'display_name' => $display]
            )->id;

            Role::query()
                ->whereHas('permissions', fn ($query) => $query->where('name', $sibling))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$id]));
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(self::PERMISSIONS))->delete();
    }
};
