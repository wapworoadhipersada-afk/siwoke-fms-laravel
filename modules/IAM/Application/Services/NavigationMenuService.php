<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Models\NavigationAccessAudit;
use Modules\IAM\Domain\Models\NavigationMenu;
use Spatie\Permission\Models\Role;
use stdClass;

/**
 * Builds the sidebar. Hiding a link is only a convenience: every page and action still checks its own
 * permission, so a wrong setting here can make a menu confusing but never lets anyone in.
 *
 * Rules, in order: an entry must be switched on; its required permission must be held; if the person's roles
 * have a setting for it, at least one of them must show it; a group with nothing left to show disappears.
 * An entry no role has a setting for is shared: everyone with the permission sees it.
 */
final class NavigationMenuService
{
    /**
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    public function forUser(User $user): array
    {
        $this->ensureCatalog();

        $roleIds = array_map(intval(...), $user->roles()->pluck('roles.id')->all());

        return $this->build($roleIds, static fn (?string $permission): bool => $permission === null || $user->can($permission));
    }

    /**
     * The sidebar as a role would see it. Read-only: nothing is written. Permissions are those of the role
     * itself, not of the administrator looking at the preview.
     *
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    public function previewForRole(Role $role): array
    {
        $this->ensureCatalog();

        $all = $role->name === RoleName::SuperAdmin->value;

        return $this->build([(int) $role->id], static fn (?string $permission): bool => $permission === null || $all || $role->hasPermissionTo($permission));
    }

    /**
     * Every entry with this role's own setting, for the editor. Entries without a setting default to shown.
     *
     * @return array<int, array{id: int, key: string, parent_id: int|null, section: string, label: string, permission: string|null, is_active: bool, visible: bool, sort_order: int}>
     */
    public function settingsForRole(Role $role): array
    {
        $this->ensureCatalog();

        $own = DB::table('navigation_menu_role')->where('role_id', $role->id)->get()->keyBy('navigation_menu_id');

        return NavigationMenu::query()->orderBy('sort_order')->orderBy('id')->get()->map(function (NavigationMenu $menu) use ($own): array {
            $row = $own->get($menu->id);

            return [
                'id' => $menu->id,
                'key' => $menu->key,
                'parent_id' => $menu->parent_id,
                'section' => $menu->section,
                'label' => $menu->label,
                'permission' => $menu->permission_name,
                'is_active' => $menu->is_active,
                'visible' => $row === null ? true : (bool) $row->is_visible,
                'sort_order' => $row === null ? $menu->sort_order : (int) $row->sort_order,
            ];
        })->values()->all();
    }

    /**
     * Replaces everything a role says about the menu, atomically, and keeps a before/after snapshot.
     *
     * @param  array<int, array{menu_id: int, is_visible: bool, sort_order: int}>  $items
     */
    public function syncRole(Role $role, array $items, ?User $actor): void
    {
        $known = NavigationMenu::query()->pluck('id')->all();

        foreach ($items as $item) {
            if (! in_array($item['menu_id'], $known, true)) {
                throw ValidationException::withMessages(['items' => 'Ada menu yang tidak dikenal.']);
            }
        }

        DB::transaction(function () use ($role, $items, $actor): void {
            $snapshot = static fn (): array => DB::table('navigation_menu_role')
                ->where('role_id', $role->id)
                ->orderBy('navigation_menu_id')
                ->get(['navigation_menu_id', 'is_visible', 'sort_order'])
                ->map(static fn (object $row): array => (array) $row)
                ->all();

            $before = $snapshot();
            $now = now();

            DB::table('navigation_menu_role')->where('role_id', $role->id)->delete();

            $rows = array_map(static fn (array $item): array => [
                'navigation_menu_id' => $item['menu_id'],
                'role_id' => $role->id,
                'is_visible' => $item['is_visible'],
                'sort_order' => $item['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $items);

            if ($rows !== []) {
                DB::table('navigation_menu_role')->insert($rows);
            }

            $this->audit('navigation.role_synced', Role::class, $role->id, $before, $snapshot(), $actor);
        });
    }

    /**
     * @param  array<mixed>|null  $before
     * @param  array<mixed>|null  $after
     */
    public function audit(string $event, string $subjectType, int|string|null $subjectId, ?array $before, ?array $after, ?User $actor): void
    {
        NavigationAccessAudit::query()->create([
            'actor_id' => $actor?->id,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * A new installation has an empty table; fill it from the catalog so the sidebar is never blank.
     */
    public function ensureCatalog(): void
    {
        if (! NavigationMenu::query()->exists()) {
            app(SyncNavigationCatalog::class)->handle();
        }
    }

    /**
     * @param  array<int, int>  $roleIds
     * @param  Closure(?string): bool  $mayUse
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    private function build(array $roleIds, Closure $mayUse): array
    {
        $menus = NavigationMenu::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        // What this person's roles say about each entry: [menu id => rows of (is_visible, sort_order)].
        $settings = DB::table('navigation_menu_role')->whereIn('role_id', $roleIds)->get()->groupBy('navigation_menu_id');

        $visible = [];

        foreach ($menus as $menu) {
            $rows = $settings->get($menu->id, collect());
            $shown = $rows->isEmpty() || $rows->contains(static fn (stdClass $row): bool => (bool) $row->is_visible);

            if (! $shown || ! $mayUse($menu->permission_name)) {
                continue;
            }

            // A page whose route no longer exists is skipped rather than allowed to break every page.
            if ($menu->route_name !== null && ! Route::has($menu->route_name)) {
                continue;
            }

            // An address that is not a plain path inside this application is never rendered as a link.
            if ($menu->url !== null && ! NavigationUrl::isSafe($menu->url)) {
                continue;
            }

            $own = $rows->filter(static fn (stdClass $row): bool => (bool) $row->is_visible)->pluck('sort_order')->map(static fn (mixed $order): int => (int) $order)->min();

            $visible[$menu->id] = [
                'id' => $menu->id,
                'key' => $menu->key,
                'parent_id' => $menu->parent_id,
                'section' => $menu->section,
                'label' => $menu->label,
                'icon' => $menu->icon,
                'href' => $menu->route_name !== null ? route($menu->route_name, absolute: false) : $menu->url,
                'order' => $own ?? $menu->sort_order,
            ];
        }

        return $this->arrange($visible);
    }

    /**
     * @param  array<int, array<string, mixed>>  $visible
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    private function arrange(array $visible): array
    {
        $children = [];

        foreach ($visible as $id => $item) {
            if ($item['parent_id'] !== null) {
                // An entry inside a group goes with that group; if the group is hidden, it is hidden too.
                if (isset($visible[$item['parent_id']])) {
                    $children[$item['parent_id']][] = $item;
                }

                unset($visible[$id]);
            }
        }

        $byOrder = static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']];
        $leaf = static fn (array $item): array => ['id' => $item['id'], 'key' => $item['key'], 'label' => $item['label'], 'icon' => $item['icon'], 'href' => $item['href']];
        $sections = [];

        foreach ($visible as $item) {
            $kids = collect($children[$item['id']] ?? [])->sort($byOrder)->map($leaf)->values()->all();

            // A group with nothing inside to open is not worth showing.
            if ($item['href'] === null && $kids === []) {
                continue;
            }

            $sections[$item['section']][] = [...$item, 'kids' => $kids];
        }

        $result = [];

        foreach ($sections as $label => $items) {
            usort($items, $byOrder);

            $result[] = [
                'first' => min(array_map(static fn (array $item): int => (int) $item['order'], $items)),
                'label' => (string) $label,
                'items' => array_map(static fn (array $item): array => [...$leaf($item), 'children' => $item['kids']], $items),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['first'] <=> $b['first']);

        return array_map(static fn (array $section): array => ['label' => $section['label'], 'items' => $section['items']], $result);
    }
}
