<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Application\Actions\DeleteLocation;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Application\Services\LocationAccess;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Merchant;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Tests\Support\MasterFixtures as Fx;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $vendor = Fx::vendor();
    $this->west = Fx::chain();
    $this->east = Fx::chain();
    $this->westTerminal = Fx::terminalIn($vendor, $this->west['area']);
    $this->eastTerminal = Fx::terminalIn($vendor, $this->east['area']);
});

function scopedUser(RoleName $role, array $locations = []): User
{
    $user = User::factory()->create()->assignRole($role->value);

    if ($locations !== []) {
        app(SyncUserLocationScopes::class)->handle($user, array_map(fn (Location $l) => $l->id, $locations));
    }

    return $user;
}

it('lets a user see only the subtree assigned to them', function () {
    $user = scopedUser(RoleName::Coordinator, [$this->west['province']]);

    expect(Location::query()->visibleTo($user)->pluck('id')->all())
        ->toEqualCanonicalizing(array_values(array_map(fn (Location $l) => $l->id, $this->west)))
        ->and(Terminal::query()->visibleTo($user)->pluck('id')->all())->toBe([$this->westTerminal->id])
        ->and(MerchantSite::query()->visibleTo($user)->pluck('id')->all())->toBe([$this->westTerminal->merchant_site_id])
        ->and(Merchant::query()->visibleTo($user)->pluck('id')->all())->toBe([$this->westTerminal->merchant_id]);
});

it('treats a lower assignment as that branch only', function () {
    $user = scopedUser(RoleName::Leader, [$this->west['service_point']]);

    $visible = Location::query()->visibleTo($user)->pluck('id')->all();

    expect($visible)->toEqualCanonicalizing([$this->west['service_point']->id, $this->west['area']->id])
        ->and($visible)->not->toContain($this->west['province']->id);
});

it('combines several assignments', function () {
    $user = scopedUser(RoleName::Leader, [$this->west['service_point'], $this->east['service_point']]);

    expect(Terminal::query()->visibleTo($user)->count())->toBe(2);
});

it('shows nothing to a user with no assignment', function () {
    $user = scopedUser(RoleName::Coordinator);

    expect(Location::query()->visibleTo($user)->count())->toBe(0)
        ->and(Terminal::query()->visibleTo($user)->count())->toBe(0)
        ->and(Merchant::query()->visibleTo($user)->count())->toBe(0);
});

it('shows everything to roles that hold the all-areas permission', function (RoleName $role) {
    $user = User::factory()->create()->assignRole($role->value);

    expect(Location::query()->visibleTo($user)->count())->toBe(6)
        ->and(Terminal::query()->visibleTo($user)->count())->toBe(2);
})->with([RoleName::SuperAdmin, RoleName::Managerial]);

it('ignores an assignment pointing at a deleted location', function () {
    $stray = Fx::location(LocationType::Province);
    $user = scopedUser(RoleName::Coordinator, [$stray]);
    app(DeleteLocation::class)->handle($stray);

    expect(Location::query()->visibleTo($user)->count())->toBe(0);
});

it('answers whether a user may access a given location', function () {
    $user = scopedUser(RoleName::Coordinator, [$this->west['service_point']]);
    $access = app(LocationAccess::class);

    expect($access->canAccess($user, $this->west['area']->fresh()))->toBeTrue()
        ->and($access->canAccess($user, $this->west['province']->fresh()))->toBeFalse()
        ->and($access->canAccess($user, $this->east['area']->fresh()))->toBeFalse();
});

it('replaces the assignments and audits the change', function () {
    $user = scopedUser(RoleName::Coordinator, [$this->west['province']]);

    app(SyncUserLocationScopes::class)->handle($user, [$this->east['province']->id, $this->east['province']->id]);

    expect(app(LocationAccess::class)->rootPaths($user))->toBe([$this->east['province']->fresh()->path]);

    $activity = Activity::query()->where('event', 'location_scope_changed')->latest('id')->first();

    expect($activity->properties['before'])->toBe([$this->west['province']->id])
        ->and($activity->properties['after'])->toBe([$this->east['province']->id]);
});

it('clears all assignments when given none', function () {
    $user = scopedUser(RoleName::Coordinator, [$this->west['province']]);

    app(SyncUserLocationScopes::class)->handle($user, []);

    expect(app(LocationAccess::class)->rootPaths($user))->toBe([]);
});

it('rejects an unknown location id', function () {
    app(SyncUserLocationScopes::class)->handle(User::factory()->create(), [999999]);
})->throws(ValidationException::class);
