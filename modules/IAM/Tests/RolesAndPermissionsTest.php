<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\IAM\Database\Seeders\DevUserSeeder;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Permissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('creates every role and every permission in the catalog', function () {
    expect(Role::query()->pluck('name')->all())->toEqualCanonicalizing(array_map(fn (RoleName $r) => $r->value, RoleName::cases()))
        ->and(Permission::query()->count())->toBe(count(Permissions::all()));
});

it('is idempotent', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::query()->count())->toBe(count(RoleName::cases()))
        ->and(Permission::query()->count())->toBe(count(Permissions::all()));
});

it('lets a seed re-sync a role back to the matrix', function () {
    $leader = Role::findByName(RoleName::Leader->value);
    $leader->givePermissionTo(Permissions::IAM_ROLE_MANAGE);

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName(RoleName::Leader->value)->hasPermissionTo(Permissions::IAM_ROLE_MANAGE))->toBeFalse();
});

it('gives a technician only field-work permissions', function () {
    $technician = User::factory()->create()->assignRole(RoleName::Technician->value);

    expect($technician->can(Permissions::MOBILE_ACCESS))->toBeTrue()
        ->and($technician->can(Permissions::TICKET_WORK))->toBeTrue()
        ->and($technician->can(Permissions::TICKET_ASSIGN))->toBeFalse()
        ->and($technician->can(Permissions::IAM_USER_VIEW))->toBeFalse()
        ->and($technician->can(Permissions::REPORT_EXPORT))->toBeFalse();
});

it('lets a leader assign tickets but not manage roles', function () {
    $leader = User::factory()->create()->assignRole(RoleName::Leader->value);

    expect($leader->can(Permissions::TICKET_ASSIGN))->toBeTrue()
        ->and($leader->can(Permissions::IAM_ROLE_MANAGE))->toBeFalse()
        ->and($leader->can(Permissions::MOBILE_ACCESS))->toBeFalse();
});

it('lets a super admin pass any ability, including ones nobody has defined', function () {
    $admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);

    expect(Gate::forUser($admin)->allows(Permissions::IAM_ROLE_MANAGE))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('some.future.permission'))->toBeTrue();
});

it('denies a user with no role everything', function () {
    $nobody = User::factory()->create();

    expect($nobody->can(Permissions::TICKET_VIEW))->toBeFalse();
});

it('audits role changes with the role names', function () {
    $user = User::factory()->create();

    $user->assignRole(RoleName::Coordinator->value);
    $user->removeRole(RoleName::Coordinator->value);

    $events = $user->activitiesAsSubject()->whereIn('event', ['role_attached', 'role_detached'])->orderBy('id')->get();

    expect($events->pluck('event')->all())->toBe(['role_attached', 'role_detached'])
        ->and($events->first()->properties['roles'])->toBe([RoleName::Coordinator->value]);
});

it('seeds one dev account per role in the testing environment', function () {
    $this->seed(DevUserSeeder::class);

    expect(User::query()->where('email', 'like', '%@fms.test')->count())->toBe(count(RoleName::cases()))
        ->and(User::query()->where('email', 'technician@fms.test')->first()->hasRole(RoleName::Technician->value))->toBeTrue();
});
