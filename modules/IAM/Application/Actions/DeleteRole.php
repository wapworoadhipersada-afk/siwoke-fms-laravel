<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Shared\Support\Audit;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Removes a role nobody holds. Built-in roles stay; a role still assigned to someone must be emptied first,
 * so a deletion can never silently strip people of their access.
 */
final class DeleteRole
{
    public function handle(Role $role, User $actor): void
    {
        if ((bool) $role->getAttribute('is_system')) {
            throw ValidationException::withMessages(['role' => 'Peran bawaan tidak dapat dihapus.']);
        }

        $holders = DB::table('model_has_roles')->where('role_id', $role->id)->count();

        if ($holders > 0) {
            throw ValidationException::withMessages(['role' => "Peran ini masih dipegang {$holders} pengguna. Pindahkan mereka ke peran lain dulu."]);
        }

        $name = $role->name;
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Audit::record('iam', 'role_deleted', "Peran dihapus: {$name}", $actor, null, ['role' => $name]);
    }
}
