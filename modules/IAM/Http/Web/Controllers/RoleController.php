<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Application\Actions\DeleteRole;
use Modules\IAM\Application\Actions\SaveRole;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\PermissionCatalog;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ModuleController;
use Spatie\Permission\Models\Role;

/**
 * Where an administrator decides what each role may do. Super Admin is shown but locked.
 */
final class RoleController extends ModuleController
{
    public function index(): Response
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $holders = DB::table('model_has_roles')->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');

        return Inertia::render('iam/roles/Index', [
            'roles' => Role::query()->withCount('permissions')->orderBy('id')->get()->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $this->label($role),
                'description' => $role->getAttribute('description'),
                'permissions_count' => (int) $role->getAttribute('permissions_count'),
                'users_count' => (int) ($holders[$role->id] ?? 0),
                'is_system' => (bool) $role->getAttribute('is_system'),
                'locked' => $role->name === RoleName::SuperAdmin->value,
            ])->values()->all(),
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        return Inertia::render('iam/roles/Form', ['role' => null, 'groups' => PermissionCatalog::groups(), 'canGrantPrivileged' => $this->isSuperAdmin()]);
    }

    public function store(Request $request, SaveRole $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $role = $this->translatingErrors([], fn (): Role => $action->handle(null, $this->validated($request), $this->user()));
        $this->success('Peran dibuat.');

        return to_route('iam.roles.edit', $role->id);
    }

    public function edit(Role $role): Response
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        return Inertia::render('iam/roles/Form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->getAttribute('label') ?? $role->name,
                'description' => $role->getAttribute('description'),
                'is_system' => (bool) $role->getAttribute('is_system'),
                'locked' => $role->name === RoleName::SuperAdmin->value,
                'permissions' => $role->permissions()->pluck('name')->values()->all(),
            ],
            'groups' => PermissionCatalog::groups(),
            'canGrantPrivileged' => $this->isSuperAdmin(),
        ]);
    }

    public function update(Request $request, Role $role, SaveRole $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $this->translatingErrors([], fn (): Role => $action->handle($role, $this->validated($request), $this->user()));
        $this->success('Peran disimpan.');

        return to_route('iam.roles.edit', $role->id);
    }

    public function destroy(Role $role, DeleteRole $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        try {
            $action->handle($role, $this->user());
            $this->success('Peran dihapus.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('iam.roles.index');
    }

    /**
     * @return array{name?: string|null, label: string, description?: string|null, permissions: list<string>}
     */
    private function validated(Request $request): array
    {
        /** @var array{name?: string|null, label: string, description?: string|null, permissions?: list<string>|null} $data */
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9 _-]+$/'],
            'description' => ['nullable', 'string', 'max:300'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:80'],
        ]);

        return [...$data, 'permissions' => array_values($data['permissions'] ?? [])];
    }

    private function label(Role $role): string
    {
        return (string) ($role->getAttribute('label') ?? $role->name);
    }

    private function isSuperAdmin(): bool
    {
        return $this->user()->hasRole(RoleName::SuperAdmin->value);
    }
}
