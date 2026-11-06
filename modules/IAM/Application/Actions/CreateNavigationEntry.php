<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Application\Services\NavigationCatalog;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\IAM\Application\Services\NavigationUrl;
use Modules\IAM\Domain\Models\NavigationMenu;

/**
 * Adds a group or a menu of the administrator's own to the sidebar. A menu leads to one of the application's
 * known pages (and then needs that page's permission) or to a plain path inside the application. It can sit
 * in a group or on its own, and always lands at the end of its place.
 */
final class CreateNavigationEntry
{
    public function __construct(private readonly NavigationMenuService $menus) {}

    /**
     * @param  array{label: string, icon: string|null, section: string}  $data
     */
    public function group(array $data, User $actor): NavigationMenu
    {
        $this->assertIcon($data['icon']);

        $section = trim($data['section']);

        if ($section === '') {
            throw ValidationException::withMessages(['section' => 'Judul bagian wajib diisi.']);
        }

        return DB::transaction(function () use ($data, $section, $actor): NavigationMenu {
            $menu = NavigationMenu::query()->create([
                'parent_id' => null,
                'key' => $this->newKey(),
                'section' => $section,
                'label' => trim($data['label']),
                'icon' => $data['icon'],
                'sort_order' => $this->endOfTopLevel(),
                'is_active' => true,
                'is_custom' => true,
            ]);

            $this->menus->audit('navigation.entry_created', NavigationMenu::class, $menu->id, null, $menu->only(['label', 'section', 'icon']), $actor);

            return $menu;
        });
    }

    /**
     * @param  array{label: string, icon: string|null, section: string|null, parent_id: int|null, page: string|null, url: string|null}  $data
     */
    public function menu(array $data, User $actor): NavigationMenu
    {
        $this->assertIcon($data['icon']);

        $page = ($data['page'] ?? '') !== '' ? (string) $data['page'] : null;
        $url = ($data['url'] ?? '') !== '' ? trim((string) $data['url']) : null;

        if (($page === null) === ($url === null)) {
            throw ValidationException::withMessages(['page' => 'Pilih satu halaman, atau isi alamat sendiri, tidak keduanya.']);
        }

        $permission = null;

        if ($page !== null) {
            $entry = collect(NavigationCatalog::entries())->first(static fn (array $entry): bool => $entry['route'] === $page);

            if ($entry === null) {
                throw ValidationException::withMessages(['page' => 'Halaman tidak tersedia.']);
            }

            $permission = $entry['permission'];
        }

        if ($url !== null && ! NavigationUrl::isSafe($url)) {
            throw ValidationException::withMessages(['url' => 'Alamat harus berupa path di aplikasi ini, misalnya /tickets (diawali satu garis miring).']);
        }

        $parent = null;

        if ($data['parent_id'] !== null) {
            $parent = NavigationMenu::query()->find($data['parent_id']);

            if (! $parent instanceof NavigationMenu || ! $parent->isGroup()) {
                throw ValidationException::withMessages(['parent_id' => 'Grup tujuan tidak valid.']);
            }
        }

        $section = $parent instanceof NavigationMenu ? $parent->section : trim((string) ($data['section'] ?? ''));

        if ($section === '') {
            throw ValidationException::withMessages(['section' => 'Judul bagian wajib diisi untuk menu tanpa grup.']);
        }

        return DB::transaction(function () use ($data, $page, $url, $permission, $parent, $section, $actor): NavigationMenu {
            $menu = NavigationMenu::query()->create([
                'parent_id' => $parent?->id,
                'key' => $this->newKey(),
                'section' => $section,
                'label' => trim($data['label']),
                'route_name' => $page,
                'url' => $url,
                'icon' => $data['icon'],
                'permission_name' => $permission,
                'sort_order' => $parent instanceof NavigationMenu
                    ? ((int) NavigationMenu::query()->where('parent_id', $parent->id)->max('sort_order')) + 10
                    : $this->endOfTopLevel(),
                'is_active' => true,
                'is_custom' => true,
            ]);

            $this->menus->audit('navigation.entry_created', NavigationMenu::class, $menu->id, null, $menu->only(['label', 'section', 'route_name', 'url', 'parent_id']), $actor);

            return $menu;
        });
    }

    private function assertIcon(?string $icon): void
    {
        if ($icon !== null && ! array_key_exists($icon, NavigationCatalog::icons())) {
            throw ValidationException::withMessages(['icon' => 'Ikon tidak tersedia.']);
        }
    }

    private function newKey(): string
    {
        return 'custom.'.Str::lower((string) Str::ulid());
    }

    private function endOfTopLevel(): int
    {
        return ((int) NavigationMenu::query()->whereNull('parent_id')->max('sort_order')) + 10;
    }
}
