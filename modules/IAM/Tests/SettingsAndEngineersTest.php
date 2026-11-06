<?php

declare(strict_types=1);

use App\Models\User;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\IAM\Application\Services\SyncNavigationCatalog;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Models\NavigationMenu;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SyncNavigationCatalog::class)->handle();
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

function sidebarFor(User $user): array
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

describe('Pengaturan', function () {
    it('shows cards for roles, menus and menu per role', function () {
        $this->actingAs($this->admin)->get(route('iam.settings.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('iam/settings/Index')
                ->where('cards', fn ($cards) => collect($cards)->pluck('title')->all() === ['Pengaturan Peran', 'Pengaturan Menu', 'Menu per Peran']));
    });

    it('is closed to people who may not manage roles', function () {
        $this->actingAs(User::factory()->create()->assignRole(RoleName::Coordinator->value))->get(route('iam.settings.index'))->assertForbidden();
    });
});

describe('Pengaturan Menu', function () {
    it('creates a group and puts a menu of the application\'s pages into it', function () {
        $this->actingAs($this->admin)->post(route('iam.navigation.groups.store'), ['label' => 'Integrasi Mitra', 'icon' => 'plug', 'section' => 'Menu'])->assertRedirect(route('iam.navigation.index'));

        $group = NavigationMenu::query()->where('label', 'Integrasi Mitra')->firstOrFail();
        expect($group->is_custom)->toBeTrue()->and($group->isGroup())->toBeTrue();

        $this->post(route('iam.navigation.menus.store'), ['label' => 'Laporan Tiket Saya', 'icon' => 'list', 'parent_id' => $group->id, 'page' => 'reports.tickets'])->assertSessionHasNoErrors();

        $menu = NavigationMenu::query()->where('label', 'Laporan Tiket Saya')->firstOrFail();
        expect($menu->parent_id)->toBe($group->id)->and($menu->route_name)->toBe('reports.tickets')->and($menu->permission_name)->toBe('report.view');
        expect(sidebarFor($this->admin))->toContain('Integrasi Mitra', 'Laporan Tiket Saya');
    });

    it('lets a menu stand on its own, pointing at a path inside the application', function () {
        $this->actingAs($this->admin)->post(route('iam.navigation.menus.store'), ['label' => 'Tiket Terbuka', 'section' => 'Menu', 'url' => '/tickets?status=open'])->assertSessionHasNoErrors();

        $menu = NavigationMenu::query()->where('label', 'Tiket Terbuka')->firstOrFail();
        expect($menu->parent_id)->toBeNull()->and($menu->url)->toBe('/tickets?status=open');
    });

    it('refuses addresses that leave the application or run code', function (string $url) {
        $this->actingAs($this->admin)->post(route('iam.navigation.menus.store'), ['label' => 'Buruk', 'section' => 'Menu', 'url' => $url])->assertSessionHasErrors('url');

        expect(NavigationMenu::query()->where('label', 'Buruk')->exists())->toBeFalse();
    })->with(['https://evil.example/', '//evil.example', 'javascript:alert(1)', '/a/../etc', '/x\\y', 'tickets']);

    it('wants exactly one destination, and a known page', function () {
        $this->actingAs($this->admin)->post(route('iam.navigation.menus.store'), ['label' => 'Dua', 'section' => 'Menu', 'page' => 'tickets.index', 'url' => '/tickets'])->assertSessionHasErrors('page');
        $this->post(route('iam.navigation.menus.store'), ['label' => 'Nol', 'section' => 'Menu'])->assertSessionHasErrors('page');
        $this->post(route('iam.navigation.menus.store'), ['label' => 'Asing', 'section' => 'Menu', 'page' => 'not.a.route'])->assertSessionHasErrors('page');
    });

    it('does not accept a page as the group, nor a group inside a group', function () {
        $page = NavigationMenu::query()->where('key', 'tickets')->firstOrFail();

        $this->actingAs($this->admin)->post(route('iam.navigation.menus.store'), ['label' => 'X', 'parent_id' => $page->id, 'url' => '/x'])->assertSessionHasErrors('parent_id');
    });

    it('lets only a custom menu be changed to another address, and only to a safe one', function () {
        $this->actingAs($this->admin)->post(route('iam.navigation.menus.store'), ['label' => 'Alamat', 'section' => 'Menu', 'url' => '/tickets']);
        $menu = NavigationMenu::query()->where('label', 'Alamat')->firstOrFail();

        $this->put(route('iam.navigation.update', $menu), ['label' => 'Alamat', 'url' => '/master/documents', 'is_active' => 1])->assertSessionHasNoErrors();
        expect($menu->fresh()->url)->toBe('/master/documents');

        $this->put(route('iam.navigation.update', $menu), ['label' => 'Alamat', 'url' => 'https://evil.example', 'is_active' => 1]);
        expect($menu->fresh()->url)->toBe('/master/documents');

        $shipped = NavigationMenu::query()->where('key', 'tickets')->firstOrFail();
        $this->put(route('iam.navigation.update', $shipped), ['label' => 'Tiket', 'url' => '/elsewhere', 'is_active' => 1]);
        expect($shipped->fresh()->url)->toBeNull();
    });

    it('deletes only the menus the administrator made, and a group only when empty', function () {
        $this->actingAs($this->admin)->post(route('iam.navigation.groups.store'), ['label' => 'Sementara', 'section' => 'Menu']);
        $group = NavigationMenu::query()->where('label', 'Sementara')->firstOrFail();
        $this->post(route('iam.navigation.menus.store'), ['label' => 'Isi', 'parent_id' => $group->id, 'url' => '/x']);
        $child = NavigationMenu::query()->where('label', 'Isi')->firstOrFail();

        $this->delete(route('iam.navigation.destroy', $group))->assertRedirect(route('iam.navigation.index'));
        expect(NavigationMenu::query()->whereKey($group->id)->exists())->toBeTrue();

        $this->delete(route('iam.navigation.destroy', $child))->assertSessionHasNoErrors();
        $this->delete(route('iam.navigation.destroy', $group))->assertSessionHasNoErrors();
        expect(NavigationMenu::query()->whereKey([$group->id, $child->id])->count())->toBe(0);

        $shipped = NavigationMenu::query()->where('key', 'tickets')->firstOrFail();
        $this->delete(route('iam.navigation.destroy', $shipped))->assertRedirect(route('iam.navigation.index'));
        expect(NavigationMenu::query()->whereKey($shipped->id)->exists())->toBeTrue();
    });

    it('never renders a stored address that is not a safe path', function () {
        $this->actingAs($this->admin)->post(route('iam.navigation.menus.store'), ['label' => 'Rusak', 'section' => 'Menu', 'url' => '/ok']);
        NavigationMenu::query()->where('label', 'Rusak')->update(['url' => 'javascript:alert(1)']);

        expect(sidebarFor($this->admin))->not->toContain('Rusak');
    });

    it('is closed to people without the role-management permission', function () {
        $user = User::factory()->create()->assignRole(RoleName::Coordinator->value);

        $this->actingAs($user)->post(route('iam.navigation.groups.store'), ['label' => 'X', 'section' => 'Menu'])->assertForbidden();
        $this->post(route('iam.navigation.menus.store'), ['label' => 'X', 'url' => '/x', 'section' => 'Menu'])->assertForbidden();
        $this->get(route('iam.navigation.access'))->assertForbidden();
    });
});
