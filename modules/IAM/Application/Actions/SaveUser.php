<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\PermissionCatalog;
use Modules\Shared\Support\Audit;
use Spatie\Permission\Models\Role;

/**
 * Creates a user or changes one, including which roles they hold and whether they may sign in at all.
 *
 * Guard rails: nobody changes their own roles (that is how people lock themselves out, or promote themselves);
 * roles that carry access-control permissions are handed out by Super Admin only; the last Super Admin cannot
 * be demoted or switched off; switching someone off also ends their mobile sessions on the spot.
 */
final class SaveUser
{
    public function __construct(private readonly RevokeAllUserSessions $revoke) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, employee_no?: string|null, user_code?: string|null, username?: string|null, employment_status?: string|null, nik?: string|null, address?: string|null, password?: string|null, user_type: string, is_active: bool, roles: list<string>}  $data
     * @return array{user: User, password: string|null} the password is returned once, only for a new user
     */
    public function handle(?User $user, array $data, User $actor): array
    {
        // An engineer is known by a code (ENG-0012); office staff may have none.
        $userCode = isset($data['user_code']) && trim($data['user_code']) !== '' ? mb_strtoupper(trim($data['user_code'])) : null;

        if ($data['user_type'] === UserType::Technician->value && $userCode === null) {
            throw ValidationException::withMessages(['user_code' => 'Kode user wajib diisi untuk engineer.']);
        }

        $roles = Role::query()->whereIn('name', $data['roles'])->where('guard_name', 'web')->get();

        if ($roles->count() !== count(array_unique($data['roles']))) {
            throw ValidationException::withMessages(['roles' => 'Ada peran yang tidak dikenal.']);
        }

        if ($roles->isEmpty()) {
            throw ValidationException::withMessages(['roles' => 'Pilih minimal satu peran.']);
        }

        $this->assertRolesAllowed($user, array_values(array_map(strval(...), $roles->pluck('name')->all())), $roles, $actor, (bool) $data['is_active']);

        return DB::transaction(function () use ($user, $data, $roles, $actor, $userCode): array {
            $created = ! $user instanceof User;
            $password = null;

            // A password typed by the administrator is used as given and never shown again; without one, a
            // temporary password is generated and returned once.
            $chosen = isset($data['password']) && $data['password'] !== '' ? $data['password'] : null;

            if (! $user instanceof User) {
                $user = new User;
                $password = $chosen === null ? Str::password(14, symbols: false) : null;
                $user->forceFill(['password' => Hash::make($chosen ?? (string) $password), 'email_verified_at' => now()]);
            } elseif ($chosen !== null) {
                $user->forceFill(['password' => Hash::make($chosen)]);
            }

            $wasActive = $created ? true : $user->is_active;

            $user->forceFill([
                'name' => trim($data['name']),
                'email' => Str::lower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'employee_no' => $data['employee_no'] ?? null,
                'user_code' => $userCode,
                'username' => isset($data['username']) && $data['username'] !== '' ? Str::lower(trim($data['username'])) : $user->username,
                'employment_status' => $data['employment_status'] ?? $user->employment_status,
                'nik' => $data['nik'] ?? $user->nik,
                'address' => $data['address'] ?? $user->address,
                'user_type' => UserType::from($data['user_type']),
                'is_active' => $data['is_active'],
            ])->save();

            $user->syncRoles($roles->all());

            // A new password ends every session the person had, on the web and on the phone.
            if (! $created && $chosen !== null) {
                $this->revoke->handle($user);
            }

            if ($wasActive && ! $data['is_active']) {
                // A switched-off technician must not keep a working phone.
                $this->revoke->handle($user);
            }

            Audit::record('iam', $created ? 'user_created' : 'user_updated', ($created ? 'Pengguna dibuat: ' : 'Pengguna diubah: ').$user->email, $actor, $user, [
                'roles' => $roles->pluck('name')->sort()->values()->all(),
                'is_active' => $data['is_active'],
            ]);

            return ['user' => $user, 'password' => $password];
        });
    }

    /**
     * @param  list<string>  $names
     * @param  Collection<int, Role>  $roles
     */
    private function assertRolesAllowed(?User $user, array $names, $roles, User $actor, bool $active): void
    {
        $current = $user instanceof User ? $user->roles()->pluck('name')->sort()->values()->all() : [];
        $requested = collect($names)->sort()->values()->all();
        $rolesChange = $current !== $requested;

        if ($user instanceof User && $user->is($actor) && $rolesChange) {
            throw ValidationException::withMessages(['roles' => 'Anda tidak dapat mengubah peran Anda sendiri.']);
        }

        if ($user instanceof User && $user->is($actor) && ! $active) {
            throw ValidationException::withMessages(['is_active' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.']);
        }

        $isSuperAdmin = $actor->hasRole(RoleName::SuperAdmin->value);

        if ($rolesChange && ! $isSuperAdmin) {
            $touched = $roles->filter(fn (Role $role): bool => ! in_array($role->name, $current, true))
                ->merge(Role::query()->whereIn('name', array_diff($current, $names))->get());

            foreach ($touched as $role) {
                $privileged = $role->name === RoleName::SuperAdmin->value
                    || array_intersect($role->permissions()->pluck('name')->all(), PermissionCatalog::privileged()) !== [];

                if ($privileged) {
                    throw ValidationException::withMessages(['roles' => "Peran {$role->name} memberi hak mengatur akses; hanya Super Admin yang dapat memberikan atau mencabutnya."]);
                }
            }
        }

        $losesSuperAdmin = $user instanceof User
            && $user->hasRole(RoleName::SuperAdmin->value)
            && (! in_array(RoleName::SuperAdmin->value, $names, true) || ! $active);

        if ($losesSuperAdmin) {
            $others = User::query()->role(RoleName::SuperAdmin->value)->where('is_active', true)->whereKeyNot($user->id)->count();

            if ($others === 0) {
                throw ValidationException::withMessages(['roles' => 'Ini Super Admin terakhir yang aktif; tidak dapat dicabut atau dinonaktifkan.']);
            }
        }
    }
}
