<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Application\Actions\SyncUserVendors;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\Merchant;
use Modules\Master\Tests\Support\MasterFixtures as Fx;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->bri = Fx::vendor('BRI');
    $this->bca = Fx::vendor('BCA');
    $this->briMerchant = Fx::merchant($this->bri);
    $this->bcaMerchant = Fx::merchant($this->bca);
    $this->user = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

it('does not limit a person who has no vendors assigned', function () {
    $access = app(VendorAccess::class);

    expect($access->allowedIds($this->user))->toBeNull()
        ->and($access->mayUse($this->user, $this->bri->id))->toBeTrue()
        ->and($access->mayUse($this->user, $this->bca->id))->toBeTrue();
});

it('limits a person to the vendors assigned, and only those', function () {
    app(SyncUserVendors::class)->handle($this->user, [$this->bri->id], $this->user);

    $access = app(VendorAccess::class);

    expect($access->allowedIds($this->user))->toBe([$this->bri->id])
        ->and($access->mayUse($this->user, $this->bca->id))->toBeFalse()
        ->and($access->selectableIds($this->user))->toBe([$this->bri->id])
        ->and($access->constrain(Merchant::query(), $this->user, 'vendor_id')->pluck('id')->all())->toBe([$this->briMerchant->id]);
});

it('lets one person cover several vendors', function () {
    app(SyncUserVendors::class)->handle($this->user, [$this->bri->id, $this->bca->id], $this->user);

    expect(app(VendorAccess::class)->allowedIds($this->user))->toEqualCanonicalizing([$this->bri->id, $this->bca->id]);
});

it('refuses a vendor that does not exist', function () {
    app(SyncUserVendors::class)->handle($this->user, [999999], $this->user);
})->throws(ValidationException::class);

it('forgets a limit once the vendors are cleared', function () {
    $sync = app(SyncUserVendors::class);
    $sync->handle($this->user, [$this->bri->id], $this->user);
    $sync->handle($this->user, [], $this->user);

    expect(app(VendorAccess::class)->allowedIds($this->user))->toBeNull();
});

describe('the header focus', function () {
    it('narrows the lists to the chosen vendor without removing access', function () {
        $this->actingAs($this->user)->put(route('focus.vendor'), ['vendor' => $this->bri->ulid])->assertRedirect();

        $this->get(route('master.merchants.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('merchants.data', 1)->where('merchants.data.0.ulid', $this->briMerchant->ulid));

        $this->put(route('focus.vendor'), ['vendor' => ''])->assertRedirect();

        $this->get(route('master.merchants.index'))->assertInertia(fn ($page) => $page->has('merchants.data', 2));
    });

    it('shares the current focus and the options with every page', function () {
        $this->actingAs($this->user)->put(route('focus.vendor'), ['vendor' => $this->bca->ulid]);

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('vendorFocus.current', $this->bca->ulid)
            ->has('vendorFocus.options', 2));
    });

    it('ignores a vendor the person is not allowed to use', function () {
        app(SyncUserVendors::class)->handle($this->user, [$this->bri->id], $this->user);

        $this->actingAs($this->user)->put(route('focus.vendor'), ['vendor' => $this->bca->ulid])->assertRedirect();

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('vendorFocus.current', null));
    });

    it('drops a stale focus when the vendor is later taken away', function () {
        $this->actingAs($this->user)->put(route('focus.vendor'), ['vendor' => $this->bca->ulid]);

        app(SyncUserVendors::class)->handle($this->user, [$this->bri->id], $this->user);

        expect(app(VendorAccess::class)->focusedId($this->user))->toBeNull();
    });

    it('needs a signed-in user', function () {
        $this->put(route('focus.vendor'), ['vendor' => $this->bri->ulid])->assertRedirect(route('login'));
    });
});
