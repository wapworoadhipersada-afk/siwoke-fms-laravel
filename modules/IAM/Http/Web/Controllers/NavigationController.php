<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Application\Actions\CreateNavigationEntry;
use Modules\IAM\Application\Actions\DeleteNavigationEntry;
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

    /**
     * Pengaturan Menu: the structure of the sidebar for everyone. Groups, menus, where they lead and in which
     * order. Who sees what is a separate screen (access()).
     */
    public function index(): Response
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        // Reading the editor is also what makes a fresh installation fill its table from the catalog.
        $this->menus->ensureCatalog();

        $all = NavigationMenu::query()->get();

        return Inertia::render('iam/navigation/Index', [
            'menus' => $this->inTreeOrder($all)->map(fn (NavigationMenu $menu): array => [
                'id' => $menu->id,
                'key' => $menu->key,
                'parent_id' => $menu->parent_id,
                'section' => $menu->section,
                'label' => $menu->label,
                'icon' => $menu->icon,
                'route_name' => $menu->route_name,
                'url' => $menu->url,
                'is_group' => $menu->isGroup(),
                'is_custom' => $menu->is_custom,
                'permission_name' => $menu->permission_name,
                'is_active' => $menu->is_active,
                'sort_order' => $menu->sort_order,
            ])->values()->all(),
            'sections' => $all->whereNull('parent_id')->pluck('section')->unique()->values()->all(),
            'pages' => collect(NavigationCatalog::entries())->filter(fn (array $entry): bool => $entry['route'] !== null)
                ->map(fn (array $entry): array => ['value' => (string) $entry['route'], 'label' => $entry['label']])->unique('value')->values()->all(),
            'icons' => collect(NavigationCatalog::icons())->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values()->all(),
            'audits' => $this->audits(),
        ]);
    }

    /**
     * Menu per Peran: which entries one role sees and in which order, with a preview of that role's sidebar.
     */
    public function access(Request $request): Response
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $roles = Role::query()->orderBy('id')->get();
        $selected = $roles->firstWhere('id', (int) $request->query('role')) ?? $roles->first();

        return Inertia::render('iam/navigation/Access', [
            'roles' => $roles->map(fn (Role $role): array => ['id' => $role->id, 'label' => (string) ($role->getAttribute('label') ?? $role->name)])->values()->all(),
            'selectedRole' => $selected?->id,
            'settings' => $selected instanceof Role ? $this->menus->settingsForRole($selected) : [],
            'preview' => $selected instanceof Role ? $this->menus->previewForRole($selected) : [],
            'audits' => $this->audits(),
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
            'items' => ['required', 'array', 'max:300'],
            'items.*.menu_id' => ['required', 'integer'],
            'items.*.is_visible' => ['required', 'boolean'],
            'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        $this->translatingErrors([], fn () => $this->menus->syncRole($role, $data['items'], $this->user()));
        $this->success('Menu untuk peran ini disimpan.');

        return to_route('iam.navigation.access', ['role' => $role->id]);
    }

    public function storeGroup(Request $request, CreateNavigationEntry $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:40'],
            'section' => ['required', 'string', 'max:40'],
        ]);

        $action->group(['label' => $data['label'], 'icon' => ($data['icon'] ?? '') !== '' ? $data['icon'] : null, 'section' => $data['section']], $this->user());
        $this->success('Grup menu dibuat.');

        return to_route('iam.navigation.index');
    }

    public function storeMenu(Request $request, CreateNavigationEntry $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:40'],
            'section' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'string', 'max:160'],
            'url' => ['nullable', 'string', 'max:255'],
        ]);

        $action->menu([
            'label' => $data['label'],
            'icon' => ($data['icon'] ?? '') !== '' ? $data['icon'] : null,
            'section' => $data['section'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'page' => $data['page'] ?? null,
            'url' => $data['url'] ?? null,
        ], $this->user());
        $this->success('Menu ditambahkan. Atur siapa yang melihatnya di Menu per Peran.');

        return to_route('iam.navigation.index');
    }

    public function destroy(NavigationMenu $menu, DeleteNavigationEntry $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        try {
            $action->handle($menu, $this->user());
            $this->success('Dihapus.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('iam.navigation.index');
    }

    public function update(Request $request, NavigationMenu $menu, UpdateNavigationMenu $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        /** @var array{label: string, icon?: string|null, section?: string|null, is_active?: bool|null, parent_id?: int|null, url?: string|null} $data */
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:40'],
            'section' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'integer'],
            'url' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $action->handle($menu, [
                'label' => $data['label'],
                'icon' => ($data['icon'] ?? '') !== '' ? $data['icon'] : null,
                'section' => ($data['section'] ?? '') !== '' ? (string) $data['section'] : $menu->section,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'parent_id' => $data['parent_id'] ?? null,
                'url' => $data['url'] ?? null,
            ], $this->user());
            $this->success('Menu disimpan.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('iam.navigation.index');
    }

    public function move(Request $request, NavigationMenu $menu, MoveNavigationMenu $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];
        $action->handle($menu, $direction, $this->user());

        return to_route('iam.navigation.index');
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
     * @return array<int, array{id: int, event: string, actor: string, at: string|null}>
     */
    private function audits(): array
    {
        return NavigationAccessAudit::query()->with('actor')->latest('id')->limit(15)->get()->map(fn (NavigationAccessAudit $audit): array => [
            'id' => $audit->id,
            'event' => $audit->event,
            'actor' => $audit->actor->name ?? 'Sistem',
            'at' => $audit->created_at?->toIso8601String(),
        ])->values()->all();
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
