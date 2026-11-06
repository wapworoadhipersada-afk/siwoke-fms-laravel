<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Application\Actions\MoveNavigationMenu;
use Modules\IAM\Application\Actions\UpdateNavigationMenu;
use Modules\IAM\Application\Services\NavigationCatalog;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\IAM\Application\Services\SyncNavigationCatalog;
use Modules\IAM\Domain\Models\NavigationAccessAudit;
use Modules\IAM\Domain\Models\NavigationMenu;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ModuleController;
use Spatie\Permission\Models\Role;

/**
 * The sidebar editor. One half edits the entries everyone shares (name, icon, group, order, on or off), the other
 * how a single role sees them (shown or hidden, and in which order), with a live preview of that role's sidebar.
 * Hiding an entry never grants or removes access: every page still checks its own permission.
 */
final class NavigationController extends ModuleController
{
    public function __construct(private readonly NavigationMenuService $menus) {}

    public function index(Request $request): Response
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $roles = Role::query()->orderBy('id')->get();
        $selected = $roles->firstWhere('id', (int) $request->query('role')) ?? $roles->first();

        // Reading the editor is also what makes a fresh installation fill its table from the catalog.
        $settings = $selected instanceof Role ? $this->menus->settingsForRole($selected) : [];

        return Inertia::render('iam/navigation/Index', [
            'roles' => $roles->map(fn (Role $role): array => ['id' => $role->id, 'label' => (string) ($role->getAttribute('label') ?? $role->name)])->values()->all(),
            'selectedRole' => $selected?->id,
            'settings' => $settings,
            'preview' => $selected instanceof Role ? $this->menus->previewForRole($selected) : [],
            'menus' => $this->inTreeOrder(NavigationMenu::query()->get())->map(fn (NavigationMenu $menu): array => [
                'id' => $menu->id,
                'key' => $menu->key,
                'parent_id' => $menu->parent_id,
                'section' => $menu->section,
                'label' => $menu->label,
                'icon' => $menu->icon,
                'route_name' => $menu->route_name,
                'permission_name' => $menu->permission_name,
                'is_active' => $menu->is_active,
                'sort_order' => $menu->sort_order,
            ])->values()->all(),
            'icons' => collect(NavigationCatalog::icons())->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values()->all(),
            'audits' => NavigationAccessAudit::query()->with('actor')->latest('id')->limit(15)->get()->map(fn (NavigationAccessAudit $audit): array => [
                'id' => $audit->id,
                'event' => $audit->event,
                'actor' => $audit->actor->name ?? 'Sistem',
                'at' => $audit->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * Saves how one role sees the menu: for every entry, shown or hidden, and its position.
     */
    public function syncRole(Request $request, Role $role): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        /** @var array{items: list<array{menu_id: int, is_visible: bool, sort_order: int}>} $data */
        $data = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*.menu_id' => ['required', 'integer'],
            'items.*.is_visible' => ['required', 'boolean'],
            'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        $this->translatingErrors([], fn () => $this->menus->syncRole($role, $data['items'], $this->user()));
        $this->success('Pengaturan menu peran disimpan.');

        return to_route('iam.navigation.index', ['role' => $role->id]);
    }

    public function update(Request $request, NavigationMenu $menu, UpdateNavigationMenu $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        /** @var array{label: string, icon?: string|null, section?: string|null, is_active?: bool|null, parent_id?: int|null} $data */
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:40'],
            'section' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'integer'],
            'role' => ['nullable', 'integer'],
        ]);

        try {
            $action->handle($menu, [
                'label' => $data['label'],
                'icon' => $data['icon'] ?? null,
                'section' => ($data['section'] ?? '') !== '' ? (string) $data['section'] : $menu->section,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'parent_id' => $data['parent_id'] ?? null,
            ], $this->user());
            $this->success('Menu disimpan.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('iam.navigation.index', array_filter(['role' => $request->input('role')]));
    }

    public function move(Request $request, NavigationMenu $menu, MoveNavigationMenu $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];
        $action->handle($menu, $direction, $this->user());

        return to_route('iam.navigation.index', array_filter(['role' => $request->input('role')]));
    }

    /**
     * Adds catalog pages that are missing (for example after an update that brought a new screen).
     * Existing entries are never touched.
     */
    public function sync(SyncNavigationCatalog $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $added = $action->handle();
        $this->success($added === 0 ? 'Semua halaman sudah ada di menu.' : "{$added} halaman ditambahkan ke menu.");

        return to_route('iam.navigation.index');
    }

    /**
     * Groups before their pages, headings in the order they appear in the sidebar, so the table reads like the menu.
     *
     * @param  Collection<int, NavigationMenu>  $menus
     * @return Collection<int, NavigationMenu>
     */
    private function inTreeOrder($menus)
    {
        $byOrder = static fn (NavigationMenu $a, NavigationMenu $b): int => [$a->sort_order, $a->id] <=> [$b->sort_order, $b->id];
        $tops = $menus->filter(static fn (NavigationMenu $menu): bool => $menu->parent_id === null);
        $headings = $tops->groupBy('section')->map(static fn ($group) => $group->min('sort_order'))->sort()->keys();
        $ordered = collect();

        foreach ($headings as $heading) {
            foreach ($tops->where('section', $heading)->sort($byOrder) as $top) {
                $ordered->push($top);
                $ordered = $ordered->merge($menus->where('parent_id', $top->id)->sort($byOrder)->values());
            }
        }

        return $ordered->values();
    }
}
