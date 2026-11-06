<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use Modules\IAM\Domain\Models\NavigationMenu;

/**
 * Adds the pages of the catalog that are not in the database yet. It never touches an entry that already exists,
 * so whatever an administrator renamed, reordered or switched off survives every deploy. Safe to run any time.
 */
final class SyncNavigationCatalog
{
    /**
     * @return int how many entries were added
     */
    public function handle(): int
    {
        $added = 0;
        $ids = NavigationMenu::query()->pluck('id', 'key')->all();
        $position = [];

        foreach (NavigationCatalog::entries() as $entry) {
            $position[$entry['parent'] ?? '_root'] = ($position[$entry['parent'] ?? '_root'] ?? 0) + 1;

            if (isset($ids[$entry['key']])) {
                continue;
            }

            $menu = NavigationMenu::query()->create([
                'parent_id' => $entry['parent'] === null ? null : ($ids[$entry['parent']] ?? null),
                'key' => $entry['key'],
                'section' => $entry['section'],
                'label' => $entry['label'],
                'route_name' => $entry['route'],
                'icon' => $entry['icon'],
                'permission_name' => $entry['permission'],
                // Spaced out, so an entry can be slipped between two others later without renumbering.
                'sort_order' => $position[$entry['parent'] ?? '_root'] * 10,
                'is_active' => true,
            ]);

            $ids[$entry['key']] = $menu->id;
            $added++;
        }

        return $added;
    }
}
