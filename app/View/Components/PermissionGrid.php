<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * PermissionGrid
 *
 * Compact multi-column checkbox grid grouped by module for staff create/edit forms.
 */
class PermissionGrid extends Component
{
    /**
     * @param  Collection<string, Collection<int, \App\Models\Permission>>  $permissionsByModule
     * @param  array<string, list<string>>  $roleDefaultsMap
     * @param  list<string>  $selectedPermissions
     * @param  list<string>  $disabledPermissions
     */
    public function __construct(
        public Collection $permissionsByModule,
        public array $roleDefaultsMap,
        public array $selectedPermissions,
        public array $disabledPermissions,
        public string $initialRole,
        public string $roleSelectName = 'role',
    ) {}

    /**
     * Render the permission grid.
     */
    public function render(): View
    {
        return view('components.permission-grid');
    }
}
