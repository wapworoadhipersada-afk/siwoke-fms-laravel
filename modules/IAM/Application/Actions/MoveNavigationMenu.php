<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\IAM\Domain\Models\NavigationMenu;

/**
 * Moves an entry one place up or down among its neighbours (same group, or same heading at the top level).
 * Neighbours are renumbered first, so the swap is exact even if earlier edits left gaps or ties.
 */
final class MoveNavigationMenu
{
    public function __construct(private readonly NavigationMenuService $menus) {}

    public function handle(NavigationMenu $menu, string $direction, User $actor): void
    {
        DB::transaction(function () use ($menu, $direction, $actor): void {
            $siblings = NavigationMenu::query()
                ->where('parent_id', $menu->parent_id)
                ->when($menu->parent_id === null, fn ($query) => $query->where('section', $menu->section))
                ->orderBy('sort_order')->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            $index = $siblings->search(fn (NavigationMenu $item): bool => $item->id === $menu->id);
            $target = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || $target < 0 || $target >= $siblings->count()) {
                return;
            }

            $before = $siblings->pluck('sort_order', 'id')->all();
            $order = $siblings->all();
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

            foreach ($order as $position => $item) {
                if ($item->sort_order !== ($position + 1) * 10) {
                    $item->forceFill(['sort_order' => ($position + 1) * 10])->save();
                }
            }

            $this->menus->audit('navigation.menu_moved', NavigationMenu::class, $menu->id, $before, collect($order)->pluck('sort_order', 'id')->all(), $actor);
        });
    }
}
