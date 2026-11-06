<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\PermissionCatalog;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Support\Audit;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a role or changes one: its display name, a short description and, above all, what it may do.
 *
 * Three rules keep this screen from becoming a way around the rest of the security:
 *  - Super Admin is fixed. Nobody edits the role that bypasses every check.
 *  - Permissions that control access itself are granted or taken back by Super Admin only.
 *  - A role's built-in name (its identifier in code) never changes; only its label does.
 */
final class SaveRole
{
    /**
     * @param  array{name?: string|null, label: string, description?: string|null, permissions: list<string>}  $data
     */
    public function handle(?Role $role, array $data, User $actor): Role
    {
        if ($role instanceof Role && $role->name === RoleName::SuperAdmin->value) {
            throw ValidationException::withMessages(['role' => 'Peran Super Admin tidak dapat diubah.']);
        }

        $permissions = array_values(array_unique($data['permissions']));
        $unknown = array_diff($permissions, Permissions::all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['permissions' => 'Ada izin yang tidak dikenal.']);
        }

        $this->assertMayChangePrivileged($role, $permissions, $actor);

        return DB::transaction(function () use ($role, $data, $permissions, $actor): Role {
            $created = ! $role instanceof Role;

            if (! $role instanceof Role) {
                $name = Str::slug((string) ($data['name'] ?? $data['label']));

                if ($name === '' || Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
                    throw ValidationException::withMessages(['name' => 'Nama peran sudah dipakai atau tidak valid.']);
                }

                $role = Role::query()->create(['name' => $name, 'guard_name' => 'web']);
            }

            $before = $created ? [] : $role->permissions()->pluck('name')->sort()->values()->all();

            $role->forceFill([
                'label' => trim($data['label']),
                'description' => isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null,
            ]);

            // Once a person has set a role's permissions, a deploy must not put them back to the defaults.
            if ($created || $before !== collect($permissions)->sort()->values()->all()) {
                $role->forceFill(['is_customized' => true]);
            }

            $role->save();
            $role->syncPermissions($permissions);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            Audit::record('iam', $created ? 'role_created' : 'role_updated', ($created ? 'Peran dibuat: ' : 'Peran diubah: ').$role->name, $actor, $role, [
                'before' => $before,
                'after' => collect($permissions)->sort()->values()->all(),
            ]);

            return $role->refresh();
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function assertMayChangePrivileged(?Role $role, array $permissions, User $actor): void
    {
        if ($actor->hasRole(RoleName::SuperAdmin->value)) {
            return;
        }

        $had = $role instanceof Role ? $role->permissions()->pluck('name')->all() : [];
        $changed = array_merge(array_diff($permissions, $had), array_diff($had, $permissions));

        if (array_intersect($changed, PermissionCatalog::privileged()) !== []) {
            throw ValidationException::withMessages(['permissions' => 'Izin yang mengatur akses hanya dapat diberikan atau dicabut oleh Super Admin.']);
        }
    }
}
