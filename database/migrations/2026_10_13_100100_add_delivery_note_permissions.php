<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Delivery note permissions for databases seeded before the module existed.
 *
 * PermissionSeeder stays the catalogue of record. This only attaches the new
 * permissions to roles that already work with quotations, so the menu shows
 * after migrate without re-running RolePermissionSeeder (which syncs and would
 * wipe custom role edits).
 *
 *   view, create, convert  -> roles that can create quotations
 *   cancel                 -> roles that can void invoices (Admin)
 */
return new class extends Migration
{
    /**
     * @var array<string, array{display: string, description: string|null}>
     */
    private const PERMISSIONS = [
        'delivery_notes.view' => ['display' => 'View Delivery Notes', 'description' => null],
        'delivery_notes.create' => ['display' => 'Create Delivery Notes', 'description' => 'Issues an albarán and takes stock out at once'],
        'delivery_notes.cancel' => ['display' => 'Cancel Delivery Notes', 'description' => 'Returns the goods to stock'],
        'delivery_notes.convert' => ['display' => 'Convert Delivery Notes to Invoice', 'description' => null],
    ];

    /**
     * Insert the permissions and attach them next to their sibling permission.
     */
    public function up(): void
    {
        $ids = [];
        foreach (self::PERMISSIONS as $name => $meta) {
            $ids[$name] = Permission::query()->updateOrCreate(
                ['name' => $name],
                [
                    'module' => 'delivery_notes',
                    'display_name' => $meta['display'],
                    'description' => $meta['description'],
                ]
            )->id;
        }

        $this->attach(['delivery_notes.view', 'delivery_notes.create', 'delivery_notes.convert'], 'quotations.create', $ids);
        $this->attach(['delivery_notes.cancel'], 'invoices.void', $ids);
    }

    /**
     * Drop the permissions. Pivot rows cascade with the permission delete.
     */
    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(self::PERMISSIONS))->delete();
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, int>  $ids
     */
    private function attach(array $names, string $sibling, array $ids): void
    {
        Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', $sibling))
            ->each(function (Role $role) use ($names, $ids): void {
                $role->permissions()->syncWithoutDetaching(array_map(fn (string $name): int => $ids[$name], $names));
            });
    }
};
