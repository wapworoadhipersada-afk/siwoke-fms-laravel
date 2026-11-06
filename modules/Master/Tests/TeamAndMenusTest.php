<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\IAM\Application\Services\SyncNavigationCatalog;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Application\Actions\SyncUserVendors;
use Modules\Master\Application\Services\MerchantSheetReader;
use Modules\Master\Tests\Support\MasterFixtures as Fx;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SyncNavigationCatalog::class)->handle();
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

function person(RoleName $role, string $name, array $locations = [], array $vendors = []): User
{
    $user = User::factory()->create(['name' => $name, 'user_type' => $role === RoleName::Technician ? UserType::Technician : UserType::Staff])->assignRole($role->value);

    if ($locations !== []) {
        app(SyncUserLocationScopes::class)->handle($user, array_map(fn ($l) => $l->id, $locations));
    }

    if ($vendors !== []) {
        app(SyncUserVendors::class)->handle($user, array_map(fn ($v) => $v->id, $vendors));
    }

    return $user;
}

describe('Tim', function () {
    it('shows each vendor with its coordinator, the leaders under them and the engineers under each leader', function () {
        $bri = Fx::vendor('BRI');
        $west = Fx::chain($bri);
        $east = Fx::chain($bri);

        person(RoleName::Coordinator, 'Koordinator Barat', [$west['province']], [$bri]);
        person(RoleName::Leader, 'Leader Barat', [$west['service_point']], [$bri]);
        person(RoleName::Technician, 'Teknisi Barat', [$west['area']], [$bri]);
        person(RoleName::Technician, 'Teknisi Timur', [$east['area']], [$bri]);

        $this->actingAs($this->admin)->get(route('master.team.index'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('master/team/Index')
                ->where('teams.0.vendor.code', 'BRI')
                ->where('teams.0.coordinators.0.name', 'Koordinator Barat')
                ->where('teams.0.coordinators.0.leaders.0.name', 'Leader Barat')
                ->where('teams.0.coordinators.0.leaders.0.engineers.0.name', 'Teknisi Barat')
                ->where('teams.0.loose_engineers.0.name', 'Teknisi Timur'));
    });

    it('keeps a vendor\'s team apart from another vendor\'s', function () {
        $bri = Fx::vendor('BRI');
        $bca = Fx::vendor('BCA');
        $briChain = Fx::chain($bri);

        person(RoleName::Coordinator, 'Koordinator BRI', [$briChain['province']], [$bri]);
        person(RoleName::Technician, 'Teknisi BCA', [$briChain['area']], [$bca]);

        $this->actingAs($this->admin)->get(route('master.team.index'))
            ->assertInertia(fn ($page) => $page
                ->where('teams.1.vendor.code', 'BRI')
                ->where('teams.1.coordinators.0.leaders', [])
                ->where('teams.1.loose_engineers', []));
    });

    it('is closed to people who may not view users', function () {
        $this->actingAs(person(RoleName::Technician, 'Teknisi'))->get(route('master.team.index'))->assertForbidden();
    });
});

describe('Pengguna and Engineer / Teknisi', function () {
    it('lists office accounts under Pengguna and leaders and engineers under their own menu', function () {
        person(RoleName::Leader, 'Si Leader');
        person(RoleName::Technician, 'Si Teknisi');
        person(RoleName::Coordinator, 'Si Koordinator');

        $this->actingAs($this->admin)->get(route('iam.users.index'))
            ->assertInertia(fn ($page) => $page->where('field', false)
                ->where('users.data', fn ($rows) => collect($rows)->pluck('name')->contains('Si Koordinator')
                    && ! collect($rows)->pluck('name')->contains('Si Leader')
                    && ! collect($rows)->pluck('name')->contains('Si Teknisi')));

        $this->get(route('iam.engineers.index'))
            ->assertInertia(fn ($page) => $page->where('field', true)
                ->where('users.data', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Si Leader', 'Si Teknisi']));
    });

    it('puts Engineer / Teknisi and Tim in the sidebar', function () {
        $labels = collect(app(NavigationMenuService::class)->forUser($this->admin->fresh()))
            ->flatMap(fn ($section) => $section['items'])
            ->flatMap(fn ($item) => [$item['label'], ...array_column($item['children'], 'label')])->all();

        expect($labels)->toContain('Engineer / Teknisi', 'Tim', 'Dokumen', 'Provinsi', 'Service Point', 'Area Kelolaan');
    });
});

describe('Lokasi', function () {
    it('has a page per level', function () {
        $chain = Fx::chain();

        foreach (['provinces' => 'province', 'service-points' => 'service_point', 'areas' => 'area'] as $path => $type) {
            $this->actingAs($this->admin)->get("/master/{$path}")->assertOk()
                ->assertInertia(fn ($page) => $page->where('fixedType', $type)->has('locations.data', 1));
        }

        expect($chain)->toHaveCount(3);
    });
});

describe('reading an Excel file', function () {
    it('reads an .xlsx sheet by its column titles, in any order', function () {
        $path = tempnam(sys_get_temp_dir(), 'doc').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['TID', 'Nama Merchant', 'Area', 'Kolom Lain']));
        $writer->addRow(Row::fromValues([145678, 'Toko Madura Bekasi', 'Bekasi Kota', 'x']));
        $writer->close();

        $rows = app(MerchantSheetReader::class)->read(new UploadedFile($path, 'daftar.xlsx', null, null, true));

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['tid'])->toBe('145678')
            ->and($rows[0]['merchant_name'])->toBe('Toko Madura Bekasi')
            ->and($rows[0]['area'])->toBe('Bekasi Kota');

        @unlink($path);
    });

    it('refuses a sheet without the TID column', function () {
        $csv = UploadedFile::fake()->createWithContent('x.csv', "Nama Merchant,Alamat\nToko,Jl\n");

        expect(fn () => app(MerchantSheetReader::class)->read($csv))->toThrow(ValidationException::class);
    });
});
