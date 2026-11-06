<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\IAM\Domain\Models\NavigationMenu;

/**
 * Removes a group or menu the administrator added. Pages that ship with the application cannot be deleted (they
 * would simply come back on the next update); they can be switched off instead. A group must be emptied first,
 * so no menu disappears by accident.
 */
final class DeleteNavigationEntry
{
    public function __construct(private readonly NavigationMenuService $menus) {}

    public function handle(NavigationMenu $menu, User $actor): void
    {
        if (! $menu->is_custom) {
            throw ValidationException::withMessages(['menu' => 'Menu bawaan tidak dapat dihapus. Matikan saja bila tidak dipakai.']);
        }

        DB::transaction(function () use ($menu, $actor): void {
            $locked = NavigationMenu::query()->whereKey($menu->id)->lockForUpdate()->first();

            if (! $locked instanceof NavigationMenu) {
                return;
            }

            if (NavigationMenu::query()->where('parent_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['menu' => 'Grup masih berisi menu. Pindahkan atau hapus isinya dulu.']);
            }

            $before = $locked->only(['label', 'section', 'route_name', 'url', 'parent_id']);
            $locked->delete();

            $this->menus->audit('navigation.entry_deleted', NavigationMenu::class, $menu->id, $before, null, $actor);
        });
    }
}
