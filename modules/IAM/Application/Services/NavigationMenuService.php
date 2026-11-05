<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\NavigationAccessAudit;
use Modules\IAM\Domain\Models\NavigationMenu;
use Spatie\Permission\Models\Role;

final class NavigationMenuService
{
    /**
     * Return a role-filtered, permission-filtered nested navigation tree.
     * Route authorization remains mandatory.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        $roleIds = $user->roles()->orderBy('roles.id')->pluck('roles.id')->all();

        return $this->buildTree(
            NavigationMenu::query()
                ->where('is_active', true)
                ->with(['roles' => fn ($query) => $query->whereIn('roles.id', $roleIds)])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            $user,
            $roleIds,
        );
    }

    /**
     * Preview uses the same rendering rules but does not persist any change.
     *
     * @param list<int> $roleIds
     * @return list<array<string, mixed>>
     */
    public function previewForRoles(array $roleIds, User $permissionContext): array
    {
        return $this->buildTree(
            NavigationMenu::query()
                ->where('is_active', true)
                ->with(['roles' => fn ($query) => $query->whereIn('roles.id', $roleIds)])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            $permissionContext,
            $roleIds,
        );
    }

    /**
     * Replace role menu settings atomically and record before/after snapshots.
     *
     * @param array<int, array{menu_id:int, is_visible:bool, sort_order:int}> $items
     */
    public function syncRole(Role $role, array $items, ?User $actor): void
    {
        DB::transaction(function () use ($role, $items, $actor): void {
            $before = DB::table('navigation_menu_role')
                ->where('role_id', $role->id)
                ->orderBy('navigation_menu_id')
                ->get(['navigation_menu_id', 'is_visible', 'sort_order'])
                ->map(fn ($row): array => (array) $row)
                ->all();

            $now = now();
            DB::table('navigation_menu_role')->where('role_id', $role->id)->delete();

            $rows = [];
            foreach ($items as $item) {
                $rows[] = [
                    'navigation_menu_id' => $item['menu_id'],
                    'role_id' => $role->id,
                    'is_visible' => $item['is_visible'],
                    'sort_order' => $item['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                DB::table('navigation_menu_role')->insert($rows);
            }

            $after = DB::table('navigation_menu_role')
                ->where('role_id', $role->id)
                ->orderBy('navigation_menu_id')
                ->get(['navigation_menu_id', 'is_visible', 'sort_order'])
                ->map(fn ($row): array => (array) $row)
                ->all();

            NavigationAccessAudit::query()->create([
                'actor_id' => $actor?->id,
                'event' => 'navigation.role_synced',
                'subject_type' => Role::class,
                'subject_id' => $role->id,
                'before' => $before,
                'after' => $after,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * @param Collection<int, NavigationMenu> $menus
     * @param list<int> $roleIds
     * @return list<array<string, mixed>>
     */
    private function buildTree(Collection $menus, User $user, array $roleIds): array
    {
        $allowed = [];

        foreach ($menus as $menu) {
            $assignments = $menu->roles;
            $visible = $assignments->isEmpty()
                || $assignments->contains(fn ($role) => in_array($role->id, $roleIds, true) && (bool) $role->pivot->is_visible);

            if (! $visible || ($menu->permission_name && ! $user->can($menu->permission_name))) {
                continue;
            }

            $roleOrder = $assignments
                ->filter(fn ($role) => in_array($role->id, $roleIds, true) && (bool) $role->pivot->is_visible)
                ->pluck('pivot.sort_order')
                ->first();

            $allowed[$menu->id] = [
                'id' => $menu->id,
                'key' => $menu->key,
                'label' => $menu->label,
                'route' => $menu->route_name,
                'icon' => $menu->icon,
                'sort_order' => $roleOrder ?? $menu->sort_order,
                'parent_id' => $menu->parent_id,
                'children' => [],
            ];
        }

        $tree = [];
        foreach (array_keys($allowed) as $id) {
            $parentId = $allowed[$id]['parent_id'];
            if ($parentId !== null && isset($allowed[$parentId])) {
                $allowed[$parentId]['children'][] = &$allowed[$id];
            } elseif ($parentId === null) {
                $tree[] = &$allowed[$id];
            }
        }

        $sort = function (array &$nodes) use (&$sort): void {
            usort($nodes, static fn (array $a, array $b): int => [$a['sort_order'], $a['id']] <=> [$b['sort_order'], $b['id']]);
            foreach ($nodes as &$node) {
                $sort($node['children']);
                unset($node['parent_id']);
            }
        };
        $sort($tree);

        return $tree;
    }
}
