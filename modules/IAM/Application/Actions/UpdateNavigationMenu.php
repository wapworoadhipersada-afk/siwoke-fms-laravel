<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Application\Services\NavigationCatalog;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\IAM\Application\Services\NavigationUrl;
use Modules\IAM\Domain\Models\NavigationMenu;

/**
 * Changes how one sidebar entry is shown: its name, icon, heading, whether it is on, and which group it sits in.
 * Where it leads and what it needs are fixed by the catalog and cannot be edited here.
 */
final class UpdateNavigationMenu
{
    public function __construct(private readonly NavigationMenuService $menus) {}

    /**
     * @param  array{label: string, icon: string|null, section: string, is_active: bool, parent_id: int|null, url?: string|null}  $data
     */
    public function handle(NavigationMenu $menu, array $data, User $actor): NavigationMenu
    {
        if ($data['icon'] !== null && ! array_key_exists($data['icon'], NavigationCatalog::icons())) {
            throw ValidationException::withMessages(['icon' => 'Ikon tidak tersedia.']);
        }

        $parentId = $data['parent_id'];

        if ($parentId !== null) {
            $parent = NavigationMenu::query()->find($parentId);

            // Only a group can hold entries, and a group cannot be put inside another one.
            if (! $parent instanceof NavigationMenu || ! $parent->isGroup() || $parent->id === $menu->id || $menu->isGroup()) {
                throw ValidationException::withMessages(['parent_id' => 'Grup tujuan tidak valid.']);
            }
        }

        // Only a menu of the administrator's own may change where it leads, and only to a safe path.
        $url = $menu->url;

        if ($menu->is_custom && $menu->url !== null && array_key_exists('url', $data)) {
            $url = trim((string) $data['url']);

            if (! NavigationUrl::isSafe($url)) {
                throw ValidationException::withMessages(['url' => 'Alamat harus berupa path di aplikasi ini, misalnya /tickets (diawali satu garis miring).']);
            }
        }

        // An entry that has a route is moved to the end of its new place, so it never lands in the middle by accident.
        $moved = $parentId !== $menu->parent_id;
        $before = $menu->only(['label', 'icon', 'section', 'is_active', 'parent_id', 'sort_order']);

        DB::transaction(function () use ($menu, $data, $parentId, $moved, $url): void {
            $menu->forceFill([
                'url' => $url,
                'label' => trim($data['label']),
                'icon' => $data['icon'],
                'section' => $parentId === null ? trim($data['section']) : $menu->section,
                'is_active' => $data['is_active'],
                'parent_id' => $parentId,
            ]);

            if ($moved) {
                $siblings = NavigationMenu::query()->where('parent_id', $parentId)->whereKeyNot($menu->id);
                $menu->forceFill(['sort_order' => ((int) $siblings->max('sort_order')) + 10]);

                if ($parentId !== null) {
                    $menu->forceFill(['section' => (string) NavigationMenu::query()->whereKey($parentId)->value('section')]);
                }
            }

            $menu->save();

            // A group's children follow it when it changes heading.
            if ($menu->isGroup()) {
                NavigationMenu::query()->where('parent_id', $menu->id)->update(['section' => $menu->section]);
            }
        });

        $this->menus->audit('navigation.menu_updated', NavigationMenu::class, $menu->id, $before, $menu->only(['label', 'icon', 'section', 'is_active', 'parent_id', 'sort_order']), $actor);

        return $menu;
    }
}
