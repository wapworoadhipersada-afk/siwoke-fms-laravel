<?php

declare(strict_types=1);

use App\Models\User;
use Modules\IAM\Application\Services\SyncNavigationCatalog;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Models\NavigationMenu;
use Modules\Master\Tests\Support\MasterFixtures;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SyncNavigationCatalog::class)->handle();
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

/**
 * The trail the browser would be given for a page, as "Title > Title > Title" (a heading shows as plain text).
 */
function trailOf(string $url): string
{
    $props = test()->actingAs(test()->admin)->get($url)->assertOk()->viewData('page')['props'];

    return collect($props['breadcrumbs'])->pluck('title')->implode(' > ');
}

it('has no trail on the dashboard', function () {
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page->where('breadcrumbs', []));
});

it('follows a page of the sidebar through its group', function () {
    expect(trailOf(route('master.vendors.index', absolute: false)))->toBe('Beranda > Master Data > Vendor')
        ->and(trailOf(route('master.provinces.index', absolute: false)))->toBe('Beranda > Lokasi > Provinsi')
        ->and(trailOf(route('iam.engineers.index', absolute: false)))->toBe('Beranda > Pengguna dan akses > Teknisi');
});

it('puts the setting screens under Pengaturan, the way the menu editor already did', function () {
    expect(trailOf(route('iam.settings.index', absolute: false)))->toBe('Beranda > Pengaturan')
        ->and(trailOf(route('iam.roles.index', absolute: false)))->toBe('Beranda > Pengaturan > Pengaturan Peran')
        ->and(trailOf(route('iam.roles.create', absolute: false)))->toBe('Beranda > Pengaturan > Pengaturan Peran > Tambah')
        ->and(trailOf(route('iam.navigation.index', absolute: false)))->toBe('Beranda > Pengaturan > Pengaturan Menu')
        ->and(trailOf(route('iam.navigation.access', absolute: false)))->toBe('Beranda > Pengaturan > Menu per Peran');
});

it('hangs add, change and detail screens under the index of their resource', function () {
    expect(trailOf(route('master.vendors.create', absolute: false)))->toBe('Beranda > Master Data > Vendor > Tambah')
        ->and(trailOf(route('iam.engineers.create', absolute: false)))->toBe('Beranda > Pengguna dan akses > Teknisi > Tambah')
        ->and(trailOf(route('master.documents.create', absolute: false)))->toBe('Beranda > Master Data > Dokumen > Tambah');
});

it('links every step except the last, and leaves a group heading without a link', function () {
    $trail = $this->actingAs($this->admin)->get(route('master.vendors.create'))->viewData('page')['props']['breadcrumbs'];

    expect($trail[0])->toBe(['title' => 'Beranda', 'href' => '/dashboard'])
        ->and($trail[1])->toBe(['title' => 'Master Data', 'href' => null])
        ->and($trail[2]['href'])->toBe('/master/vendors')
        ->and($trail[3])->toBe(['title' => 'Tambah', 'href' => null]);
});

it('uses the names the administrator gave the menu', function () {
    NavigationMenu::query()->where('key', 'master.vendors')->update(['label' => 'Daftar Vendor']);
    NavigationMenu::query()->where('key', 'master')->update(['label' => 'Data Induk']);

    expect(trailOf(route('master.vendors.index', absolute: false)))->toBe('Beranda > Data Induk > Daftar Vendor');
});

it('puts a person\'s area and vendor screen under their change screen', function () {
    $engineer = User::factory()->create(['user_type' => 'technician'])->assignRole(RoleName::Technician->value);
    $office = User::factory()->create()->assignRole(RoleName::Coordinator->value);

    expect(trailOf(route('master.user-scopes.edit', $engineer->ulid, absolute: false)))->toBe('Beranda > Pengguna dan akses > Teknisi > Ubah > Area dan vendor')
        ->and(trailOf(route('master.user-scopes.edit', $office->ulid, absolute: false)))->toBe('Beranda > Pengguna dan akses > Pengguna > Ubah > Area dan vendor');
});

it('puts a screen of a resource that has no index page of its own under the one it belongs to', function () {
    $merchant = MasterFixtures::merchant(MasterFixtures::vendor('BRI'));

    expect(trailOf(route('master.merchants.sites.create', $merchant->ulid, absolute: false)))->toBe('Beranda > Master Data > Merchant > Tambah');
});

it('gives nothing to a visitor who is not signed in', function () {
    $this->get(route('login'))->assertOk()->assertInertia(fn ($page) => $page->where('breadcrumbs', []));
});
