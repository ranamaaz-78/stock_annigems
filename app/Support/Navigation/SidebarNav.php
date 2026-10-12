<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * SidebarNav
 *
 * Turns the config tree (branch_nav / superadmin_nav) into the nodes the
 * current user may actually see, and marks which node matches this request.
 * Blade only renders; permission and active-route decisions live here so
 * both sidebars stay in lockstep.
 */
class SidebarNav
{
    /**
     * Filter a nav tree and annotate each surviving node with `active`.
     *
     * Empty groups (every child hidden) are dropped. Dashboard / profile
     * fallbacks from the old Blade loop are preserved so a user who can
     * sign in always has a home link.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function tree(array $items, ?User $user): array
    {
        $filtered = [];

        foreach ($items as $item) {
            $node = self::prepareNode($item, $user);
            if ($node !== null) {
                $filtered[] = $node;
            }
        }

        return $filtered;
    }

    /**
     * Recursively apply visibility, child filtering and the active flag.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function prepareNode(array $item, ?User $user): ?array
    {
        $children = $item['children'] ?? [];

        if ($children !== []) {
            $visibleChildren = [];
            foreach ($children as $child) {
                $prepared = self::prepareNode($child, $user);
                if ($prepared !== null) {
                    $visibleChildren[] = $prepared;
                }
            }

            // A module heading with no reachable screens is noise.
            if ($visibleChildren === []) {
                return null;
            }

            $item['children'] = $visibleChildren;
            $item['active'] = collect($visibleChildren)->contains(fn (array $child): bool => $child['active'] === true);

            return $item;
        }

        if (! self::isVisible($item, $user)) {
            return null;
        }

        $item['active'] = self::routeIsActive($item);

        return $item;
    }

    /**
     * Whether this leaf should appear for the signed-in user.
     *
     * @param  array<string, mixed>  $item
     */
    public static function isVisible(array $item, ?User $user): bool
    {
        $visible = true;

        if (! empty($item['permission'])) {
            $visible = $user?->hasPermission($item['permission']) ?? false;
        }

        if (! empty($item['policy'])) {
            $visible = $user?->can($item['policy'], User::class) ?? false;
        }

        // A `gate` entry is a model-less ability (ReportPolicy's, for
        // example). It must be checked without a model argument,
        // otherwise Laravel would route it to UserPolicy and always deny.
        if (! empty($item['gate'])) {
            $visible = $user?->can($item['gate']) ?? false;
        }

        $route = $item['route'] ?? null;

        // Settings nav must still appear when only the Module 2
        // settings.view permission exists (before a re-seed).
        if (! $visible && $route === 'branch.settings.index') {
            $visible = $user?->hasPermission('settings.view') ?? false;
        }

        if ($route === 'branch.dashboard' || $route === 'superadmin.dashboard' || $route === 'branch.profile.edit') {
            $visible = true;
        }

        return $visible;
    }

    /**
     * Match the current route against a leaf's landing route, including the
     * rest of that module (edit/show/create) but never the whole area.
     *
     * @param  array<string, mixed>  $item
     */
    public static function routeIsActive(array $item): bool
    {
        $route = $item['route'] ?? null;
        if (! ($item['enabled'] ?? false) || empty($route)) {
            return false;
        }

        if (Request::routeIs($route)) {
            return true;
        }

        // Dashboard is a single screen — broadening it to branch.* would
        // highlight it on every page.
        if (str_ends_with($route, '.dashboard')) {
            return false;
        }

        if (str_ends_with($route, '.index') || str_ends_with($route, '.scan') || str_ends_with($route, '.show')) {
            $module = (string) preg_replace('/\.(index|scan|show)$/', '.*', $route);

            return Request::routeIs($module);
        }

        return Request::routeIs(str_replace('.index', '.*', $route));
    }
}
