<?php

namespace Tests\Unit;

use App\Support\Navigation\SidebarNav;
use Tests\TestCase;

/**
 * Sidebar tree filtering: empty modules must not render, and Dashboard
 * remains reachable even without a signed-in permission set.
 */
class SidebarNavTest extends TestCase
{
    /**
     * A module heading with no visible children is omitted entirely.
     */
    public function test_empty_module_is_dropped(): void
    {
        $tree = SidebarNav::tree([
            [
                'id' => 'inventory',
                'label' => 'Inventory',
                'icon' => 'inventory_2',
                'children' => [
                    [
                        'label' => 'Jewellery',
                        'route' => 'branch.jewellery-items.index',
                        'permission' => 'jewellery.view',
                        'enabled' => true,
                    ],
                ],
            ],
        ], null);

        $this->assertSame([], $tree);
    }

    /**
     * Dashboard is the signed-in home link and is never permission-gated.
     */
    public function test_dashboard_is_always_visible(): void
    {
        $tree = SidebarNav::tree([
            [
                'id' => 'dashboard',
                'label' => 'Dashboard',
                'route' => 'branch.dashboard',
                'icon' => 'dashboard',
                'enabled' => true,
            ],
        ], null);

        $this->assertCount(1, $tree);
        $this->assertSame('Dashboard', $tree[0]['label']);
    }
}
