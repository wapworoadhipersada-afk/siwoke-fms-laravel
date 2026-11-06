<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Application\Actions\MoveNavigationMenu;
use Modules\IAM\Application\Actions\UpdateNavigationMenu;
use Modules\IAM\Application\Services\NavigationCatalog;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\IAM\Application\Services\SyncNavigationCatalog;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Models\NavigationAccessAudit;
use Modules\IAM\Domain\Models\NavigationMenu;
use Modules\IAM\Domain\Permissions;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SyncNavigationCatalog::class)->handle();
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
    $this->service = app(NavigationMenuService::class);
});

/**
 * @return list<string> every label the sidebar shows to a user, groups and pages
 */
function labelsFor(User $user): array
{
    $labels = [];

    foreach (app(NavigationMenuService::class)->forUser($user->fresh()) as $section) {
        foreach ($section['items'] as $item) {
            $labels[] = $item['label'];

            foreach ($item['children'] as $child) {
                $labels[] = $child['label'];
            }
        }
    }

    return $labels;
}

function menu(string $key): NavigationMenu
{
    return NavigationMenu::query()->where('key', $key)->firstOrFail();
}

function userWith(RoleName $role): User
{
    return User::factory()->create()->assignRole($role->value);
}

describe('the catalog', function () {
    it('puts every page of the catalog in the database, groups before their pages', function () {
        expect(NavigationMenu::query()->count())->toBe(count(NavigationCatalog::entries()))
            ->and(menu('tickets')->parent_id)->toBe(menu('operations')->id)
            ->and(menu('operations')->route_name)->toBeNull();
    });

    it('adds only what is missing and never touches what an administrator changed', function () {
        menu('tickets')->forceFill(['label' => 'Pekerjaan', 'is_active' => false, 'sort_order' => 999])->save();
        NavigationMenu::query()->where('key', 'dashboard')->delete();

        $added = app(SyncNavigationCatalog::class)->handle();

        expect($added)->toBe(1)
            ->and(menu('tickets')->label)->toBe('Pekerjaan')
            ->and(menu('tickets')->is_active)->toBeFalse()
            ->and(menu('tickets')->sort_order)->toBe(999)
            ->and(app(SyncNavigationCatalog::class)->handle())->toBe(0);
    });

    it('only points at routes that exist and permissions that exist', function () {
        foreach (NavigationCatalog::entries() as $entry) {
            if ($entry['route'] !== null) {
                expect(Route::has($entry['route']))->toBeTrue("route {$entry['route']}");
            }

            if ($entry['permission'] !== null) {
                expect(Permissions::all())->toContain($entry['permission']);
            }

            expect($entry['icon'])->toBeIn(array_keys(NavigationCatalog::icons()));
        }
    });
});

describe('who sees what', function () {
    it('shows a coordinator only the pages their permissions allow', function () {
        $labels = labelsFor(userWith(RoleName::Coordinator));

        expect($labels)->toContain('Tiket')->toContain('Aset')->toContain('Vendor')
            ->not->toContain('Peran')->not->toContain('Klien integrasi')->not->toContain('Pengaturan menu');
    });

    it('shows Super Admin everything, including the access settings', function () {
        expect(labelsFor($this->admin))->toContain('Peran')->toContain('Pengaturan menu')->toContain('Pengguna')->toContain('Klien integrasi');
    });

    it('shows a technician almost nothing of the office menu', function () {
        $labels = labelsFor(userWith(RoleName::Technician));

        expect($labels)->not->toContain('Vendor')->not->toContain('Laporan tiket')->not->toContain('Peran');
    });

    it('hides a group when nothing inside it is allowed', function () {
        expect(labelsFor(userWith(RoleName::Technician)))->not->toContain('Master Data')->not->toContain('Vendor API');
    });

    it('lets one role hide a page without affecting another role', function () {
        $coordinator = Role::findByName(RoleName::Coordinator->value);
        $settings = collect($this->service->settingsForRole($coordinator))->map(fn (array $row): array => [
            'menu_id' => $row['id'], 'is_visible' => $row['key'] !== 'tickets', 'sort_order' => $row['sort_order'],
        ])->all();

        $this->service->syncRole($coordinator, $settings, $this->admin);

        expect(labelsFor(userWith(RoleName::Coordinator)))->not->toContain('Tiket')->toContain('Laporan tiket')
            ->and(labelsFor(userWith(RoleName::OperationalAdmin)))->toContain('Tiket');
    });

    it('is only a convenience: a hidden page is still open to someone with the permission', function () {
        $coordinator = Role::findByName(RoleName::Coordinator->value);
        $this->service->syncRole($coordinator, [['menu_id' => menu('tickets')->id, 'is_visible' => false, 'sort_order' => 10]], $this->admin);
        $user = userWith(RoleName::Coordinator);

        expect(labelsFor($user))->not->toContain('Tiket');
        $this->actingAs($user)->get(route('tickets.index'))->assertOk();
    });

    it('never shows a page the role lacks permission for, whatever the setting says', function () {
        $technician = Role::findByName(RoleName::Technician->value);
        $this->service->syncRole($technician, [['menu_id' => menu('master.vendors')->id, 'is_visible' => true, 'sort_order' => 10]], $this->admin);

        expect(labelsFor(userWith(RoleName::Technician)))->not->toContain('Vendor');
    });

    it('lets a role order the menu its own way', function () {
        $coordinator = Role::findByName(RoleName::Coordinator->value);
        $settings = collect($this->service->settingsForRole($coordinator))->map(function (array $row): array {
            $order = match ($row['key']) {
                'reports.tickets' => 1,
                'tickets' => 2,
                default => $row['sort_order'],
            };

            return ['menu_id' => $row['id'], 'is_visible' => true, 'sort_order' => $order];
        })->all();
        $this->service->syncRole($coordinator, $settings, $this->admin);

        $operations = collect($this->service->forUser(userWith(RoleName::Coordinator)))->flatMap(fn (array $section) => $section['items'])->firstWhere('key', 'operations');

        expect(array_column($operations['children'], 'label'))->toBe(['Laporan tiket', 'Tiket']);
    });

    it('does not show an entry that is switched off for everyone', function () {
        menu('assets.stock')->forceFill(['is_active' => false])->save();

        expect(labelsFor($this->admin))->not->toContain('Stok')->toContain('Gudang');
    });

    it('skips an entry whose route no longer exists instead of breaking the page', function () {
        menu('assets.models')->forceFill(['route_name' => 'asset.removed.index'])->save();

        expect(labelsFor($this->admin))->not->toContain('Jenis aset')->toContain('Aset');
    });

    it('orders sections and entries by the shared order', function () {
        $sections = array_column($this->service->forUser($this->admin), 'label');

        expect($sections)->toBe(['Menu', 'Pengaturan', 'Integrasi']);

        menu('tickets')->forceFill(['sort_order' => 5])->save();
        menu('reports.tickets')->forceFill(['sort_order' => 1])->save();
        $operations = collect($this->service->forUser($this->admin))->flatMap(fn (array $section) => $section['items'])->firstWhere('key', 'operations');

        expect(array_column($operations['children'], 'label'))->toBe(['Laporan tiket', 'Tiket']);
    });
});

describe('the editor', function () {
    it('previews a role by its own permissions, not the viewer\'s, and writes nothing', function () {
        $before = DB::table('navigation_menu_role')->count();
        $audits = NavigationAccessAudit::query()->count();

        $preview = $this->service->previewForRole(Role::findByName(RoleName::Technician->value));
        $labels = collect($preview)->flatMap(fn (array $section) => $section['items'])->pluck('label')->all();

        expect($labels)->not->toContain('Master Data')->not->toContain('Pengaturan')
            ->and(DB::table('navigation_menu_role')->count())->toBe($before)
            ->and(NavigationAccessAudit::query()->count())->toBe($audits);
    });

    it('records a before and after snapshot when a role\'s menu is saved', function () {
        $coordinator = Role::findByName(RoleName::Coordinator->value);
        $this->service->syncRole($coordinator, [['menu_id' => menu('tickets')->id, 'is_visible' => false, 'sort_order' => 30]], $this->admin);

        $audit = NavigationAccessAudit::query()->latest('id')->firstOrFail();

        expect($audit->event)->toBe('navigation.role_synced')
            ->and($audit->actor_id)->toBe($this->admin->id)
            ->and($audit->before)->toBe([])
            ->and($audit->after[0]['is_visible'])->toBeFalse();
    });

    it('refuses settings for an entry that does not exist', function () {
        expect(fn () => $this->service->syncRole(Role::findByName(RoleName::Coordinator->value), [['menu_id' => 99999, 'is_visible' => true, 'sort_order' => 1]], $this->admin))
            ->toThrow(ValidationException::class);
    });

    it('moves an entry up and down among its neighbours and ignores moves past the ends', function () {
        $move = app(MoveNavigationMenu::class);

        $move->handle(menu('reports.tickets'), 'up', $this->admin);
        expect(menu('reports.tickets')->sort_order)->toBeLessThan(menu('tickets')->sort_order);

        $move->handle(menu('reports.tickets'), 'up', $this->admin);
        $move->handle(menu('reports.tickets'), 'down', $this->admin);
        expect(menu('reports.tickets')->sort_order)->toBeGreaterThan(menu('tickets')->sort_order);
    });

    it('renames, re-icons and switches off an entry', function () {
        app(UpdateNavigationMenu::class)->handle(menu('tickets'), ['label' => 'Pekerjaan', 'icon' => 'task', 'section' => 'Menu', 'is_active' => false, 'parent_id' => menu('operations')->id], $this->admin);

        $tickets = menu('tickets');
        expect($tickets->label)->toBe('Pekerjaan')->and($tickets->icon)->toBe('task')->and($tickets->is_active)->toBeFalse();
    });

    it('refuses an icon outside the list', function () {
        expect(fn () => app(UpdateNavigationMenu::class)->handle(menu('tickets'), ['label' => 'x', 'icon' => '../../evil', 'section' => 'Menu', 'is_active' => true, 'parent_id' => menu('operations')->id], $this->admin))
            ->toThrow(ValidationException::class);
    });

    it('only lets a page sit inside a group, never a group inside a group or a page inside a page', function () {
        $update = app(UpdateNavigationMenu::class);

        expect(fn () => $update->handle(menu('operations'), ['label' => 'x', 'icon' => 'list', 'section' => 'Menu', 'is_active' => true, 'parent_id' => menu('master')->id], $this->admin))->toThrow(ValidationException::class);
        expect(fn () => $update->handle(menu('tickets'), ['label' => 'x', 'icon' => 'list', 'section' => 'Menu', 'is_active' => true, 'parent_id' => menu('reports.tickets')->id], $this->admin))->toThrow(ValidationException::class);
    });

    it('puts a page that moves to another group at the end of it', function () {
        app(UpdateNavigationMenu::class)->handle(menu('tickets'), ['label' => 'Tiket', 'icon' => 'list', 'section' => 'Menu', 'is_active' => true, 'parent_id' => menu('master')->id], $this->admin);

        $labels = collect($this->service->forUser($this->admin))->flatMap(fn (array $section) => $section['items'])->firstWhere('key', 'master');

        expect(array_column($labels['children'], 'label'))->toBe(['Vendor', 'Merchant', 'TID', 'Dokumen', 'Tiket']);
    });

    it('changes the heading of a group and carries its pages with it', function () {
        app(UpdateNavigationMenu::class)->handle(menu('master'), ['label' => 'Master Data', 'icon' => 'table', 'section' => 'Data', 'is_active' => true, 'parent_id' => null], $this->admin);

        expect(menu('master.vendors')->section)->toBe('Data')->and(array_column($this->service->forUser($this->admin), 'label'))->toContain('Data');
    });

    it('fills an empty table from the catalog the first time the sidebar is built', function () {
        NavigationMenu::query()->delete();

        expect(labelsFor($this->admin))->toContain('Tiket')->and(NavigationMenu::query()->count())->toBe(count(NavigationCatalog::entries()));
    });
});

describe('the screens', function () {
    it('are closed to people who may not manage roles', function () {
        $coordinator = userWith(RoleName::Coordinator);

        $this->actingAs($coordinator)->get(route('iam.navigation.index'))->assertForbidden();
        $this->actingAs($coordinator)->put(route('iam.navigation.update', menu('tickets')), ['label' => 'x'])->assertForbidden();
        $this->actingAs($coordinator)->post(route('iam.navigation.move', menu('tickets')), ['direction' => 'up'])->assertForbidden();
        $this->actingAs($coordinator)->put(route('iam.navigation.role', Role::findByName(RoleName::Technician->value)), ['items' => []])->assertForbidden();
        expect(menu('tickets')->label)->toBe('Tiket');
    });

    it('show the editor with the settings of the chosen role', function () {
        $role = Role::findByName(RoleName::Coordinator->value);

        $this->actingAs($this->admin)->get(route('iam.navigation.index', ['role' => $role->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('iam/navigation/Index')->where('selectedRole', $role->id)->has('settings')->has('preview')->has('menus')->has('icons'));
    });

    it('save a role\'s menu from the form', function () {
        $role = Role::findByName(RoleName::Coordinator->value);

        $this->actingAs($this->admin)->put(route('iam.navigation.role', $role), ['items' => [['menu_id' => menu('tickets')->id, 'is_visible' => false, 'sort_order' => 20]]])
            ->assertRedirect(route('iam.navigation.index', ['role' => $role->id]));

        expect(labelsFor(userWith(RoleName::Coordinator)))->not->toContain('Tiket');
    });

    it('reject a malformed form', function () {
        $role = Role::findByName(RoleName::Coordinator->value);

        $this->actingAs($this->admin)->put(route('iam.navigation.role', $role), ['items' => [['menu_id' => 'x', 'is_visible' => 'maybe', 'sort_order' => -1]]])->assertSessionHasErrors();
    });

    it('share the person\'s own sidebar with every page', function () {
        $this->actingAs(userWith(RoleName::Coordinator))->get(route('dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('navigation')->where('navigation.0.label', 'Menu'));
    });
});
