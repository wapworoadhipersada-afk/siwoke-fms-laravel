<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Application\Actions\DeleteRole;
use Modules\IAM\Application\Actions\ResetUserPassword;
use Modules\IAM\Application\Actions\SaveRole;
use Modules\IAM\Application\Actions\SaveUser;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Tests\Support\MasterFixtures;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

/**
 * Someone who may manage users and roles but is not Super Admin.
 */
function delegate(): User
{
    $role = Role::query()->create(['name' => 'user-admin', 'guard_name' => 'web']);
    $role->syncPermissions([Permissions::IAM_USER_VIEW, Permissions::IAM_USER_CREATE, Permissions::IAM_USER_UPDATE, Permissions::IAM_ROLE_MANAGE]);

    return User::factory()->create()->assignRole('user-admin');
}

function userData(array $overrides = []): array
{
    return [
        'name' => 'Budi Santoso',
        'email' => 'Budi@Contoh.test',
        'phone' => '0812',
        'employee_no' => 'EMP-1',
        'user_type' => 'staff',
        'is_active' => true,
        'roles' => [RoleName::Coordinator->value],
        ...$overrides,
    ];
}

describe('roles', function () {
    it('creates a role with a readable name, a code made from it, and only the permissions chosen', function () {
        $role = app(SaveRole::class)->handle(null, ['label' => 'Supervisor Wilayah', 'description' => 'Mengawasi area', 'permissions' => [Permissions::TICKET_VIEW, Permissions::TICKET_ASSIGN]], $this->admin);

        expect($role->name)->toBe('supervisor-wilayah')
            ->and($role->getAttribute('label'))->toBe('Supervisor Wilayah')
            ->and($role->getAttribute('is_system'))->toBeFalse()
            ->and($role->permissions()->pluck('name')->sort()->values()->all())->toBe([Permissions::TICKET_ASSIGN, Permissions::TICKET_VIEW]);
    });

    it('refuses a role code that is already taken', function () {
        app(SaveRole::class)->handle(null, ['label' => 'Tim A', 'permissions' => []], $this->admin);

        expect(fn () => app(SaveRole::class)->handle(null, ['label' => 'Tim A', 'permissions' => []], $this->admin))->toThrow(ValidationException::class);
    });

    it('refuses a permission that does not exist', function () {
        expect(fn () => app(SaveRole::class)->handle(null, ['label' => 'Aneh', 'permissions' => ['ticket.fly']], $this->admin))->toThrow(ValidationException::class);
    });

    it('changes what a role may do, and the people holding it feel it at once', function () {
        $user = User::factory()->create()->assignRole(RoleName::Coordinator->value);
        expect($user->can(Permissions::REPORT_EXPORT))->toBeFalse();

        $permissions = Role::findByName(RoleName::Coordinator->value)->permissions()->pluck('name')->all();
        app(SaveRole::class)->handle(Role::findByName(RoleName::Coordinator->value), ['label' => 'Koordinator', 'permissions' => [...$permissions, Permissions::REPORT_EXPORT]], $this->admin);

        expect($user->fresh()->can(Permissions::REPORT_EXPORT))->toBeTrue();
    });

    it('keeps a built-in role\'s code when only its label changes', function () {
        $role = app(SaveRole::class)->handle(Role::findByName(RoleName::Leader->value), ['name' => 'something-else', 'label' => 'Kepala Tim', 'permissions' => Role::findByName(RoleName::Leader->value)->permissions()->pluck('name')->all()], $this->admin);

        expect($role->name)->toBe('leader')->and($role->getAttribute('label'))->toBe('Kepala Tim');
    });

    it('never lets anyone edit Super Admin', function () {
        expect(fn () => app(SaveRole::class)->handle(Role::findByName(RoleName::SuperAdmin->value), ['label' => 'Biasa', 'permissions' => []], $this->admin))->toThrow(ValidationException::class);
    });

    it('lets only Super Admin grant or take back permissions that control access', function () {
        $helper = delegate();
        $role = app(SaveRole::class)->handle(null, ['label' => 'Tim B', 'permissions' => [Permissions::TICKET_VIEW]], $helper);

        expect(fn () => app(SaveRole::class)->handle($role, ['label' => 'Tim B', 'permissions' => [Permissions::TICKET_VIEW, Permissions::IAM_ROLE_MANAGE]], $helper))->toThrow(ValidationException::class);
        expect($role->fresh()->hasPermissionTo(Permissions::IAM_ROLE_MANAGE))->toBeFalse();

        $granted = app(SaveRole::class)->handle($role, ['label' => 'Tim B', 'permissions' => [Permissions::TICKET_VIEW, Permissions::IAM_ROLE_MANAGE]], $this->admin);
        expect($granted->hasPermissionTo(Permissions::IAM_ROLE_MANAGE))->toBeTrue();

        expect(fn () => app(SaveRole::class)->handle($granted, ['label' => 'Tim B', 'permissions' => [Permissions::TICKET_VIEW]], $helper))->toThrow(ValidationException::class);
    });

    it('leaves the seeder from putting an edited role back to the defaults, but still fixes untouched ones', function () {
        $leader = Role::findByName(RoleName::Leader->value);
        app(SaveRole::class)->handle($leader, ['label' => 'Leader', 'permissions' => [Permissions::TICKET_VIEW]], $this->admin);
        $coordinator = Role::findByName(RoleName::Coordinator->value);
        $coordinator->givePermissionTo(Permissions::IAM_ROLE_MANAGE);

        $this->seed(RolesAndPermissionsSeeder::class);

        expect(Role::findByName(RoleName::Leader->value)->permissions()->pluck('name')->all())->toBe([Permissions::TICKET_VIEW])
            ->and(Role::findByName(RoleName::Coordinator->value)->hasPermissionTo(Permissions::IAM_ROLE_MANAGE))->toBeFalse();
    });

    it('gives the built-in roles a label and protects them', function () {
        foreach (RoleName::cases() as $name) {
            $role = Role::findByName($name->value);

            expect($role->getAttribute('label'))->toBe($name->label())->and($role->getAttribute('is_system'))->toBeTrue();
        }
    });

    it('deletes an unused custom role but not a built-in one or one somebody holds', function () {
        $custom = app(SaveRole::class)->handle(null, ['label' => 'Sementara', 'permissions' => []], $this->admin);
        app(DeleteRole::class)->handle($custom, $this->admin);
        expect(Role::query()->where('name', 'sementara')->exists())->toBeFalse();

        expect(fn () => app(DeleteRole::class)->handle(Role::findByName(RoleName::Leader->value), $this->admin))->toThrow(ValidationException::class);

        $busy = app(SaveRole::class)->handle(null, ['label' => 'Dipakai', 'permissions' => []], $this->admin);
        User::factory()->create()->assignRole($busy->name);
        expect(fn () => app(DeleteRole::class)->handle($busy, $this->admin))->toThrow(ValidationException::class, 'masih dipegang');
    });

    it('records who changed a role and how', function () {
        app(SaveRole::class)->handle(null, ['label' => 'Tim C', 'permissions' => [Permissions::TICKET_VIEW]], $this->admin);

        $entry = DB::table('activity_log')->where('log_name', 'iam')->where('event', 'role_created')->first();

        expect($entry)->not->toBeNull()->and($entry->causer_id)->toBe($this->admin->id);
    });
});

describe('role screens', function () {
    it('are closed without the permission, and open for Super Admin', function () {
        $this->actingAs(User::factory()->create()->assignRole(RoleName::Coordinator->value))->get(route('iam.roles.index'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('iam.roles.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('iam/roles/Index')->has('roles', 6)->where('roles.0.locked', true));
    });

    it('create a role from the form and open its editor', function () {
        $response = $this->actingAs($this->admin)->post(route('iam.roles.store'), ['label' => 'Tim D', 'permissions' => [Permissions::TICKET_VIEW]]);

        $role = Role::query()->where('name', 'tim-d')->firstOrFail();
        $response->assertRedirect(route('iam.roles.edit', $role->id));
        $this->actingAs($this->admin)->get(route('iam.roles.edit', $role->id))->assertOk()
            ->assertInertia(fn ($page) => $page->component('iam/roles/Form')->where('role.permissions', [Permissions::TICKET_VIEW])->has('groups')->where('canGrantPrivileged', true));
    });

    it('tell a delegate they cannot hand out access-control permissions', function () {
        $helper = delegate();

        $this->actingAs($helper)->get(route('iam.roles.create'))->assertOk()->assertInertia(fn ($page) => $page->where('canGrantPrivileged', false));
        $this->actingAs($helper)->post(route('iam.roles.store'), ['label' => 'Tim E', 'permissions' => [Permissions::IAM_ROLE_MANAGE]])->assertSessionHasErrors('permissions');
        expect(Role::query()->where('name', 'tim-e')->exists())->toBeFalse();
    });

    it('refuse to delete a built-in role', function () {
        $leader = Role::findByName(RoleName::Leader->value);

        $this->actingAs($this->admin)->delete(route('iam.roles.destroy', $leader->id))->assertRedirect(route('iam.roles.index'));
        expect(Role::query()->where('name', 'leader')->exists())->toBeTrue();
    });
});

describe('users', function () {
    it('creates an account with a generated password that works, lower-cased email, and the chosen roles', function () {
        $result = app(SaveUser::class)->handle(null, userData(), $this->admin);
        $user = $result['user'];

        expect($result['password'])->toBeString()->and(strlen($result['password']))->toBeGreaterThanOrEqual(14)
            ->and(Hash::check($result['password'], $user->password))->toBeTrue()
            ->and($user->email)->toBe('budi@contoh.test')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->hasRole(RoleName::Coordinator->value))->toBeTrue()
            ->and($user->is_active)->toBeTrue();
    });

    it('never stores or logs the password in plain text', function () {
        $result = app(SaveUser::class)->handle(null, userData(), $this->admin);

        expect(DB::table('users')->where('id', $result['user']->id)->value('password'))->not->toContain($result['password']);
        expect(DB::table('activity_log')->get()->pluck('properties')->implode(' '))->not->toContain($result['password']);
    });

    it('requires at least one role and refuses an unknown one', function () {
        expect(fn () => app(SaveUser::class)->handle(null, userData(['roles' => []]), $this->admin))->toThrow(ValidationException::class);
        expect(fn () => app(SaveUser::class)->handle(null, userData(['roles' => ['ghost']]), $this->admin))->toThrow(ValidationException::class);
    });

    it('changes roles and details of an existing account', function () {
        $user = app(SaveUser::class)->handle(null, userData(), $this->admin)['user'];

        $result = app(SaveUser::class)->handle($user, userData(['name' => 'Budi S.', 'roles' => [RoleName::Leader->value, RoleName::Coordinator->value]]), $this->admin);

        expect($result['password'])->toBeNull()
            ->and($user->fresh()->name)->toBe('Budi S.')
            ->and($user->fresh()->roles()->pluck('name')->sort()->values()->all())->toBe(['coordinator', 'leader']);
    });

    it('does not let someone change their own roles or switch themselves off', function () {
        $helper = delegate();

        expect(fn () => app(SaveUser::class)->handle($helper, userData(['email' => $helper->email, 'roles' => [RoleName::Leader->value]]), $helper))->toThrow(ValidationException::class, 'sendiri');
        expect(fn () => app(SaveUser::class)->handle($helper, userData(['email' => $helper->email, 'roles' => ['user-admin'], 'is_active' => false]), $helper))->toThrow(ValidationException::class, 'sendiri');
    });

    it('leaves handing out roles that control access to Super Admin', function () {
        $helper = delegate();
        $target = app(SaveUser::class)->handle(null, userData(), $helper)['user'];

        expect(fn () => app(SaveUser::class)->handle($target, userData(['roles' => [RoleName::SuperAdmin->value]]), $helper))->toThrow(ValidationException::class);
        expect(fn () => app(SaveUser::class)->handle($target, userData(['roles' => ['user-admin']]), $helper))->toThrow(ValidationException::class);
        expect($target->fresh()->hasRole(RoleName::SuperAdmin->value))->toBeFalse();

        app(SaveUser::class)->handle($target, userData(['roles' => ['user-admin']]), $this->admin);
        expect($target->fresh()->hasRole('user-admin'))->toBeTrue();
    });

    it('does not let the last active Super Admin be demoted or switched off', function () {
        $other = User::factory()->create()->assignRole(RoleName::Leader->value);

        expect(fn () => app(SaveUser::class)->handle($this->admin, userData(['email' => $this->admin->email, 'roles' => [RoleName::SuperAdmin->value], 'is_active' => false]), $other))->toThrow(ValidationException::class);

        $second = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
        app(SaveUser::class)->handle($second, userData(['email' => $second->email, 'roles' => [RoleName::Leader->value]]), $this->admin);
        expect($second->fresh()->hasRole(RoleName::Leader->value))->toBeTrue();

        expect(fn () => app(SaveUser::class)->handle($this->admin, userData(['email' => $this->admin->email, 'roles' => [RoleName::Leader->value]]), $second))->toThrow(ValidationException::class);
    });

    it('cuts off a technician\'s phone the moment the account is switched off', function () {
        $user = app(SaveUser::class)->handle(null, userData(['user_type' => 'technician', 'user_code' => 'ENG-001', 'roles' => [RoleName::Technician->value]]), $this->admin)['user'];
        $user->createToken('phone');
        expect($user->tokens()->count())->toBe(1);

        app(SaveUser::class)->handle($user, userData(['user_type' => 'technician', 'user_code' => 'ENG-001', 'roles' => [RoleName::Technician->value], 'is_active' => false]), $this->admin);

        expect($user->tokens()->count())->toBe(0)->and($user->fresh()->is_active)->toBeFalse();
    });

    it('resets a password, ends sessions, and protects Super Admin accounts from lesser admins', function () {
        $user = app(SaveUser::class)->handle(null, userData(), $this->admin)['user'];
        $user->createToken('phone');

        $password = app(ResetUserPassword::class)->handle($user, $this->admin);

        expect(Hash::check($password, $user->fresh()->password))->toBeTrue()->and($user->tokens()->count())->toBe(0);
        expect(fn () => app(ResetUserPassword::class)->handle($this->admin, delegate()))->toThrow(ValidationException::class);
    });
});

describe('user screens', function () {
    it('list and search accounts for people who may view them', function () {
        app(SaveUser::class)->handle(null, userData(['name' => 'Sari Dewi', 'email' => 'sari@contoh.test']), $this->admin);

        $this->actingAs($this->admin)->get(route('iam.users.index', ['q' => 'sari']))->assertOk()
            ->assertInertia(fn ($page) => $page->component('iam/users/Index')->has('users.data', 1)->where('users.data.0.email', 'sari@contoh.test'));

        $this->actingAs(User::factory()->create()->assignRole(RoleName::Technician->value))->get(route('iam.users.index'))->assertForbidden();
    });

    it('create an account and show the password once on the answering page', function () {
        $response = $this->actingAs($this->admin)->post(route('iam.users.store'), userData(['email' => 'baru@contoh.test']));

        $response->assertOk()->assertInertia(fn ($page) => $page->component('iam/users/Credentials')->has('password')->where('reset', false));
        expect(User::query()->where('email', 'baru@contoh.test')->exists())->toBeTrue();
        $this->actingAs($this->admin)->get(route('iam.users.index'))->assertOk()->assertInertia(fn ($page) => $page->missing('password'));
    });

    it('refuse a duplicate email', function () {
        app(SaveUser::class)->handle(null, userData(['email' => 'sama@contoh.test']), $this->admin);

        $this->actingAs($this->admin)->post(route('iam.users.store'), userData(['email' => 'SAMA@contoh.test', 'employee_no' => 'EMP-2']))->assertSessionHasErrors('email');
    });

    it('edit by public id, never by internal number', function () {
        $user = app(SaveUser::class)->handle(null, userData(), $this->admin)['user'];

        $this->actingAs($this->admin)->get(route('iam.users.edit', $user->ulid))->assertOk()
            ->assertInertia(fn ($page) => $page->component('iam/users/Form')->where('managed.ulid', $user->ulid)->where('managed.roles', ['coordinator']));
        $this->actingAs($this->admin)->get('/iam/users/'.$user->id.'/edit')->assertNotFound();
    });

    it('stay closed to someone who may only view', function () {
        $viewer = User::factory()->create()->assignRole(RoleName::OperationalAdmin->value);
        $target = app(SaveUser::class)->handle(null, userData(), $this->admin)['user'];

        $this->actingAs($viewer)->get(route('iam.users.index'))->assertOk();
        $this->actingAs($viewer)->get(route('iam.users.create'))->assertForbidden();
        $this->actingAs($viewer)->put(route('iam.users.update', $target->ulid), userData())->assertForbidden();
        $this->actingAs($viewer)->post(route('iam.users.reset-password', $target->ulid))->assertForbidden();
    });

    it('reset a password from the screen and show it once', function () {
        $target = app(SaveUser::class)->handle(null, userData(), $this->admin)['user'];

        $this->actingAs($this->admin)->post(route('iam.users.reset-password', $target->ulid))->assertOk()
            ->assertInertia(fn ($page) => $page->component('iam/users/Credentials')->has('password')->where('reset', true));
    });
});

describe('working areas', function () {
    it('are set from their own screen and open the data to that person', function () {
        $target = app(SaveUser::class)->handle(null, userData(['roles' => [RoleName::OperationalAdmin->value]]), $this->admin)['user'];
        $area = MasterFixtures::chain()['area'];

        $this->actingAs($this->admin)->get(route('master.user-scopes.edit', $target->ulid))->assertOk()
            ->assertInertia(fn ($page) => $page->component('master/user-scopes/Edit')->has('locations')->where('assigned', []));
        $this->actingAs($this->admin)->put(route('master.user-scopes.update', $target->ulid), ['location_ids' => [$area->id]])->assertRedirect();

        expect(DB::table('user_location_scopes')->where('user_id', $target->id)->pluck('location_id')->all())->toBe([$area->id]);
    });

    it('stay closed to people who may not update users', function () {
        $target = app(SaveUser::class)->handle(null, userData(), $this->admin)['user'];

        $this->actingAs(User::factory()->create()->assignRole(RoleName::OperationalAdmin->value))->get(route('master.user-scopes.edit', $target->ulid))->assertForbidden();
    });
});
