<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Merchant;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Domain\Models\Vendor;
use Modules\Master\Tests\Support\MasterFixtures as Fx;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function webUser(RoleName $role, array $scopes = []): User
{
    $user = User::factory()->create()->assignRole($role->value);

    if ($scopes !== []) {
        app(SyncUserLocationScopes::class)->handle($user, array_map(fn (Location $location) => $location->id, $scopes));
    }

    return $user;
}

describe('access control', function () {
    it('sends guests to the login page', function (string $uri) {
        $this->get($uri)->assertRedirect(route('login'));
    })->with(['/master/vendors', '/master/locations', '/master/merchants', '/master/terminals']);

    it('forbids a technician from every master page', function (string $uri) {
        $this->actingAs(webUser(RoleName::Technician))->get($uri)->assertForbidden();
    })->with(['/master/vendors', '/master/locations', '/master/merchants', '/master/terminals']);

    it('lets a viewer read but not write', function () {
        $coordinator = webUser(RoleName::Coordinator);

        $this->actingAs($coordinator)->get('/master/vendors')->assertOk();
        $this->actingAs($coordinator)->get('/master/vendors/create')->assertForbidden();
        $this->actingAs($coordinator)->post('/master/vendors', ['code' => 'BRI', 'name' => 'BRI', 'is_active' => 1])->assertForbidden();

        expect(Vendor::query()->count())->toBe(0);
    });

    it('shares the permission names with the frontend', function () {
        $this->actingAs(webUser(RoleName::Coordinator))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('ticket.assign')));
    });
});

describe('vendors', function () {
    it('lists, searches and reports whether the user may manage', function () {
        Fx::vendor('BRI');
        Fx::vendor('CIMB');

        $this->actingAs(webUser(RoleName::OperationalAdmin))->get('/master/vendors?q=cim')
            ->assertInertia(fn (Assert $page) => $page
                ->component('master/vendors/Index')
                ->has('vendors.data', 1)
                ->where('vendors.data.0.code', 'CIMB')
                ->where('can.manage', true));
    });

    it('creates, updates and deletes a vendor', function () {
        $admin = webUser(RoleName::OperationalAdmin);

        $this->actingAs($admin)->post('/master/vendors', ['code' => 'bri', 'name' => 'Bank BRI', 'is_active' => 1])
            ->assertRedirect('/master/vendors');

        $vendor = Vendor::query()->sole();
        expect($vendor->code)->toBe('BRI');

        $this->actingAs($admin)->put("/master/vendors/{$vendor->ulid}", ['code' => 'BRI', 'name' => 'BRI Persero', 'is_active' => 0])
            ->assertRedirect('/master/vendors');

        expect($vendor->fresh()->name)->toBe('BRI Persero')
            ->and($vendor->fresh()->is_active)->toBeFalse();

        $this->actingAs($admin)->delete("/master/vendors/{$vendor->ulid}")->assertRedirect('/master/vendors');

        expect(Vendor::query()->count())->toBe(0);
    });

    it('records who made the change and from where in the audit log', function () {
        $admin = webUser(RoleName::OperationalAdmin);

        $this->actingAs($admin)
            ->withHeader('User-Agent', 'FMS-Test-Browser/1.0')
            ->post('/master/vendors', ['code' => 'BRI', 'name' => 'Bank BRI', 'is_active' => 1]);

        $activity = Activity::query()->where('log_name', 'master')->where('event', 'created')->sole();

        expect($activity->causer_id)->toBe($admin->id)
            ->and($activity->properties['ip'])->not->toBeNull()
            ->and($activity->properties['user_agent'])->toBe('FMS-Test-Browser/1.0');
    });

    it('validates the input and reports a duplicate code on the field', function () {
        Fx::vendor('BRI');
        $admin = webUser(RoleName::OperationalAdmin);

        $this->actingAs($admin)->post('/master/vendors', ['code' => 'bad code!', 'name' => '', 'is_active' => 1])
            ->assertSessionHasErrors(['code', 'name']);

        $this->actingAs($admin)->post('/master/vendors', ['code' => 'bri', 'name' => 'Dup', 'is_active' => 1])
            ->assertSessionHasErrors('code');
    });

    it('keeps a vendor that still has merchants and says so', function () {
        $vendor = Fx::vendor();
        Fx::merchant($vendor);

        $this->actingAs(webUser(RoleName::OperationalAdmin))->delete("/master/vendors/{$vendor->ulid}")
            ->assertRedirect('/master/vendors');

        expect($vendor->fresh()->trashed())->toBeFalse();
    });
});

describe('locations', function () {
    it('lets a super admin build the tree level by level, with a vendor on the service point', function () {
        $admin = webUser(RoleName::SuperAdmin);
        $vendor = Fx::vendor('BRI');

        $this->actingAs($admin)->post('/master/locations', ['type' => 'province', 'code' => '32', 'name' => 'Jawa Barat', 'is_active' => 1])->assertRedirect('/master/locations');
        $province = Location::query()->where('code', '32')->sole();

        $this->actingAs($admin)->post('/master/locations', ['type' => 'service_point', 'parent' => $province->ulid, 'vendor' => $vendor->ulid, 'code' => 'SP-BDG', 'name' => 'SP Bandung', 'is_active' => 1])->assertRedirect('/master/locations');
        $servicePoint = Location::query()->where('code', 'SP-BDG')->sole();

        $this->actingAs($admin)->post('/master/locations', ['type' => 'area', 'parent' => $servicePoint->ulid, 'code' => 'AR-1', 'name' => 'Area Cicendo', 'is_active' => 1])->assertRedirect('/master/locations');
        $area = Location::query()->where('code', 'AR-1')->sole();

        expect($servicePoint->parent_id)->toBe($province->id)
            ->and($servicePoint->vendor_id)->toBe($vendor->id)
            ->and($servicePoint->path)->toBe("/{$province->id}/{$servicePoint->id}/")
            ->and($area->vendor_id)->toBe($vendor->id)
            ->and($area->path)->toBe("/{$province->id}/{$servicePoint->id}/{$area->id}/");
    });

    it('rejects the wrong parent type with a field error', function () {
        $chain = Fx::chain();

        $this->actingAs(webUser(RoleName::SuperAdmin))
            ->post('/master/locations', ['type' => 'service_point', 'parent' => $chain['service_point']->ulid, 'code' => 'SP1', 'name' => 'SP', 'is_active' => 1])
            ->assertSessionHasErrors('parent');
    });

    it('does not let the type be changed on update', function () {
        $chain = Fx::chain();

        $this->actingAs(webUser(RoleName::SuperAdmin))
            ->put("/master/locations/{$chain['service_point']->ulid}", ['type' => 'area', 'parent' => $chain['province']->ulid, 'code' => 'X', 'name' => 'X', 'is_active' => 1])
            ->assertSessionHasErrors('type');
    });

    it('filters by type and shows only what the user is assigned to', function () {
        $west = Fx::chain();
        Fx::chain();
        $user = webUser(RoleName::OperationalAdmin, [$west['service_point']]);

        $this->actingAs($user)->get('/master/locations?type=service_point')
            ->assertInertia(fn (Assert $page) => $page->has('locations.data', 1)->where('locations.data.0.ulid', $west['service_point']->ulid));
    });

    it('answers 404 for a location outside the assigned area', function () {
        $west = Fx::chain();
        $east = Fx::chain();
        $user = webUser(RoleName::OperationalAdmin, [$west['province']]);

        $this->actingAs($user)->get("/master/locations/{$west['service_point']->ulid}/edit")->assertOk();
        $this->actingAs($user)->get("/master/locations/{$east['service_point']->ulid}/edit")->assertNotFound();
        $this->actingAs($user)->delete("/master/locations/{$east['area']->ulid}")->assertNotFound();
    });

    it('forbids creating under a parent outside the assigned area', function () {
        $west = Fx::chain();
        $east = Fx::chain();
        $user = webUser(RoleName::OperationalAdmin, [$west['province']]);

        $this->actingAs($user)
            ->post('/master/locations', ['type' => 'service_point', 'parent' => $east['province']->ulid, 'code' => 'C9', 'name' => 'Sneaky', 'is_active' => 1])
            ->assertForbidden();

        $this->actingAs($user)
            ->post('/master/locations', ['type' => 'service_point', 'parent' => $west['province']->ulid, 'code' => 'C9', 'name' => 'Allowed', 'is_active' => 1])
            ->assertRedirect('/master/locations');
    });

    it('forbids a restricted user from creating a province', function () {
        $west = Fx::chain();

        $this->actingAs(webUser(RoleName::OperationalAdmin, [$west['province']]))
            ->post('/master/locations', ['type' => 'province', 'code' => '99', 'name' => 'New', 'is_active' => 1])
            ->assertForbidden();
    });

    it('only offers parents the user can see in the form', function () {
        $west = Fx::chain();
        Fx::chain();

        $this->actingAs(webUser(RoleName::OperationalAdmin, [$west['service_point']]))->get('/master/locations/create')
            ->assertInertia(fn (Assert $page) => $page
                ->has('parentOptions.province', 0)
                ->has('parentOptions.service_point', 1)
                ->has('vendors'));
    });
});

describe('merchants, sites and terminals', function () {
    it('creates a merchant under a vendor and lands on its page', function () {
        $vendor = Fx::vendor();

        $this->actingAs(webUser(RoleName::OperationalAdmin))
            ->post('/master/merchants', ['vendor' => $vendor->ulid, 'merchant_code' => 'm100', 'name' => 'Toko Maju'])
            ->assertRedirect();

        $merchant = Merchant::query()->sole();

        expect($merchant->vendor_id)->toBe($vendor->id)
            ->and($merchant->merchant_code)->toBe('M100');
    });

    it('does not accept a vendor change on update', function () {
        $merchant = Fx::merchant(Fx::vendor());

        $this->actingAs(webUser(RoleName::SuperAdmin))
            ->put("/master/merchants/{$merchant->ulid}", ['vendor' => Fx::vendor('CIMB')->ulid, 'merchant_code' => 'X', 'name' => 'X'])
            ->assertSessionHasErrors('vendor');
    });

    it('registers a site and then a TID from that site', function () {
        $admin = webUser(RoleName::SuperAdmin);
        $merchant = Fx::merchant(Fx::vendor());
        $servicePoint = Fx::chain()['area'];

        $this->actingAs($admin)->get("/master/merchants/{$merchant->ulid}/sites/create")
            ->assertInertia(fn (Assert $page) => $page->component('master/sites/Form')->has('servicePoints', 1));

        $this->actingAs($admin)->post("/master/merchants/{$merchant->ulid}/sites", [
            'location' => $servicePoint->ulid, 'address' => 'Jl. Braga 1', 'is_active' => 1,
        ])->assertRedirect("/master/merchants/{$merchant->ulid}");

        $site = MerchantSite::query()->sole();

        $this->actingAs($admin)->get("/master/terminals/create?site={$site->ulid}")
            ->assertInertia(fn (Assert $page) => $page->component('master/terminals/Form')->where('site.merchant', $merchant->name));

        $this->actingAs($admin)->post('/master/terminals', ['site' => $site->ulid, 'tid' => 'tid0001', 'status' => 'active'])
            ->assertRedirect("/master/merchants/{$merchant->ulid}");

        $terminal = Terminal::query()->sole();

        expect($terminal->tid)->toBe('TID0001')
            ->and($terminal->vendor_id)->toBe($merchant->vendor_id);

        $this->actingAs($admin)->get("/master/merchants/{$merchant->ulid}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('master/merchants/Show')
                ->has('sites', 1)
                ->where('sites.0.terminals.0.tid', 'TID0001'));
    });

    it('refuses a site on a service point instead of a managed area', function () {
        $merchant = Fx::merchant(Fx::vendor());
        $area = Fx::chain()['service_point'];

        $this->actingAs(webUser(RoleName::SuperAdmin))
            ->post("/master/merchants/{$merchant->ulid}/sites", ['location' => $area->ulid, 'address' => 'Jl. X', 'is_active' => 1])
            ->assertSessionHasErrors('location');
    });

    it('lists terminals filtered by vendor and status', function () {
        $sp = Fx::chain()['area'];
        Fx::terminalIn($bri = Fx::vendor('BRI'), $sp);
        Fx::terminalIn(Fx::vendor('CIMB'), $sp);

        $this->actingAs(webUser(RoleName::SuperAdmin))->get("/master/terminals?vendor={$bri->ulid}&status=active")
            ->assertInertia(fn (Assert $page) => $page->has('terminals.data', 1)->where('terminals.data.0.vendor', 'BRI'));
    });

    it('edits and deletes a TID and a site', function () {
        $admin = webUser(RoleName::SuperAdmin);
        $terminal = Fx::terminalIn(Fx::vendor(), Fx::chain()['area']);
        $site = $terminal->site;

        $this->actingAs($admin)->put("/master/terminals/{$terminal->ulid}", ['site' => $site->ulid, 'tid' => 'NEWTID1', 'status' => 'removed'])
            ->assertRedirect();

        expect($terminal->fresh()->tid)->toBe('NEWTID1')
            ->and($terminal->fresh()->status->value)->toBe('removed');

        $this->actingAs($admin)->delete("/master/sites/{$site->ulid}")->assertRedirect();
        expect($site->fresh()->trashed())->toBeFalse();

        $this->actingAs($admin)->delete("/master/terminals/{$terminal->ulid}")->assertRedirect();
        $this->actingAs($admin)->delete("/master/sites/{$site->ulid}")->assertRedirect();

        expect($terminal->fresh()->trashed())->toBeTrue()
            ->and($site->fresh()->trashed())->toBeTrue();
    });

    it('will not move a TID to a site of a different merchant through the form', function () {
        $vendor = Fx::vendor();
        $sp = Fx::chain()['area'];
        $terminal = Fx::terminalIn($vendor, $sp);
        $otherSite = Fx::site(Fx::merchant($vendor), $sp);

        $this->actingAs(webUser(RoleName::SuperAdmin))
            ->put("/master/terminals/{$terminal->ulid}", ['site' => $otherSite->ulid, 'tid' => $terminal->tid, 'status' => 'active'])
            ->assertStatus(422);
    });

    it('hides merchants, sites and terminals outside the assigned area', function () {
        $west = Fx::chain();
        $east = Fx::chain();
        $vendor = Fx::vendor();
        $westTerminal = Fx::terminalIn($vendor, $west['area']);
        $eastTerminal = Fx::terminalIn($vendor, $east['area']);
        $user = webUser(RoleName::OperationalAdmin, [$west['province']]);

        $this->actingAs($user)->get("/master/merchants/{$westTerminal->merchant->ulid}")->assertOk();
        $this->actingAs($user)->get("/master/merchants/{$eastTerminal->merchant->ulid}")->assertNotFound();
        $this->actingAs($user)->get("/master/terminals/{$eastTerminal->ulid}/edit")->assertNotFound();
        $this->actingAs($user)->get("/master/sites/{$eastTerminal->site->ulid}/edit")->assertNotFound();
        $this->actingAs($user)->get("/master/terminals/create?site={$eastTerminal->site->ulid}")->assertNotFound();
        $this->actingAs($user)->get('/master/terminals')
            ->assertInertia(fn (Assert $page) => $page->has('terminals.data', 1)->where('terminals.data.0.ulid', $westTerminal->ulid));
    });

    it('lets a restricted user reach a merchant they just created, before it has a site', function () {
        $west = Fx::chain();
        $user = webUser(RoleName::OperationalAdmin, [$west['province']]);
        $vendor = Fx::vendor();

        $response = $this->actingAs($user)->post('/master/merchants', ['vendor' => $vendor->ulid, 'merchant_code' => 'NEW1', 'name' => 'Baru']);
        $response->assertRedirect();

        $this->actingAs($user)->get($response->headers->get('Location'))->assertOk();
    });

    it('forbids a restricted user from placing a site in another area', function () {
        $west = Fx::chain();
        $east = Fx::chain();
        $user = webUser(RoleName::OperationalAdmin, [$west['province']]);
        $merchant = Fx::merchant(Fx::vendor());

        $this->actingAs($user)->post("/master/merchants/{$merchant->ulid}/sites", [
            'location' => $east['area']->ulid, 'address' => 'Jl. Luar', 'is_active' => 1,
        ])->assertForbidden();
    });
});

it('uses a managed area as the only site location', function () {
    expect(LocationType::Area->parentType())->toBe(LocationType::ServicePoint);
});
