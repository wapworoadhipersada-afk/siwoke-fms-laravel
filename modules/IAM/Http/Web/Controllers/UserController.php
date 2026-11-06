<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Web\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Application\Actions\ResetUserPassword;
use Modules\IAM\Application\Actions\SaveUser;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\FieldAccount;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ModuleController;
use Spatie\Permission\Models\Role;

/**
 * Staff and technician accounts: who they are, which roles they hold, and whether they may sign in.
 * Passwords are generated, shown once to the administrator, and never stored in a form or a link.
 */
final class UserController extends ModuleController
{
    public function index(Request $request): Response
    {
        return $this->listing($request, false);
    }

    public function engineers(Request $request): Response
    {
        return $this->listing($request, true);
    }

    private function listing(Request $request, bool $field): Response
    {
        $this->allow(Permissions::IAM_USER_VIEW);

        $search = trim((string) $request->query('q'));
        $role = trim((string) $request->query('role'));
        $viewer = $this->user();
        $fieldRoles = FieldAccount::roles();

        $users = User::query()
            ->with('roles')
            // Office accounts and field accounts (leaders and engineers) have separate menus.
            ->where(function ($query) use ($field, $fieldRoles): void {
                if ($field) {
                    $query->whereHas('roles', fn ($inner) => $inner->whereIn('name', $fieldRoles))->orWhere('user_type', UserType::Technician->value);
                } else {
                    $query->whereDoesntHave('roles', fn ($inner) => $inner->whereIn('name', $fieldRoles))->where('user_type', '!=', UserType::Technician->value);
                }
            })
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('email', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('employee_no', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->when($role !== '', fn ($query) => $query->whereHas('roles', fn ($inner) => $inner->where('name', $role)))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'ulid' => $user->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'employee_no' => $user->employee_no,
                'user_code' => $user->user_code,
                'photo_url' => $user->photo_url,
                'user_type_label' => $user->user_type->label(),
                'phone' => $user->phone,
                'employment_label' => $user->employment_status?->label(),
                'roles' => array_values($user->roles->map(fn (Model $item): string => (string) ($item->getAttribute('label') ?? $item->getAttribute('name')))->all()),
                'is_active' => $user->is_active,
                'is_self' => $user->is($viewer),
            ]);

        return Inertia::render('iam/users/Index', [
            'users' => $users,
            'filters' => ['q' => $search, 'role' => $role],
            'field' => $field,
            'roles' => collect($this->roleOptions())->filter(fn (array $option): bool => in_array($option['name'], $fieldRoles, true) === $field)->values()->all(),
            'can' => [
                'create' => $viewer->can(Permissions::IAM_USER_CREATE),
                'update' => $viewer->can(Permissions::IAM_USER_UPDATE),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::IAM_USER_CREATE);

        return Inertia::render('iam/users/Form', ['managed' => null, 'roles' => $this->officeRoleOptions(), 'types' => $this->officeTypeOptions()]);
    }

    public function store(Request $request, SaveUser $action): Response
    {
        $this->allow(Permissions::IAM_USER_CREATE);

        $result = $this->translatingErrors([], fn (): array => $action->handle(null, $this->validated($request, null), $this->user()));

        return Inertia::render('iam/users/Credentials', [
            'account' => ['name' => $result['user']->name, 'email' => $result['user']->email],
            'password' => $result['password'],
            'reset' => false,
            'sectionHref' => route('iam.users.index', absolute: false),
            'coverageHref' => null,
        ]);
    }

    public function edit(User $managedUser): Response|RedirectResponse
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        // Leaders and engineers are changed on their own form.
        if (FieldAccount::is($managedUser)) {
            return to_route('iam.engineers.edit', $managedUser->ulid);
        }

        return Inertia::render('iam/users/Form', [
            'managed' => [
                'ulid' => $managedUser->ulid,
                'name' => $managedUser->name,
                'email' => $managedUser->email,
                'phone' => $managedUser->phone,
                'employee_no' => $managedUser->employee_no,
                'user_code' => $managedUser->user_code,
                'photo_url' => $managedUser->photo_url,
                'photo_full_url' => $managedUser->photo_path === null ? null : '/iam/users/'.$managedUser->ulid.'/photo',
                'user_type' => $managedUser->user_type->value,
                'is_active' => $managedUser->is_active,
                'roles' => $managedUser->roles()->pluck('name')->values()->all(),
                'is_self' => $managedUser->is($this->user()),
            ],
            'roles' => $this->officeRoleOptions(),
            'types' => $this->officeTypeOptions(),
        ]);
    }

    public function update(Request $request, User $managedUser, SaveUser $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        $this->translatingErrors([], fn (): array => $action->handle($managedUser, $this->validated($request, $managedUser), $this->user()));
        $this->success('Pengguna disimpan.');

        return to_route('iam.users.edit', $managedUser->ulid);
    }

    public function resetPassword(User $managedUser, ResetUserPassword $action): Response
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        $password = $this->translatingErrors([], fn (): string => $action->handle($managedUser, $this->user()));

        return Inertia::render('iam/users/Credentials', [
            'account' => ['name' => $managedUser->name, 'email' => $managedUser->email],
            'password' => $password,
            'reset' => true,
            'sectionHref' => FieldAccount::is($managedUser) ? route('iam.engineers.index', absolute: false) : route('iam.users.index', absolute: false),
            'coverageHref' => null,
        ]);
    }

    /**
     * @return array{name: string, email: string, phone?: string|null, employee_no?: string|null, user_code?: string|null, user_type: string, is_active: bool, roles: list<string>}
     */
    private function validated(Request $request, ?User $managed): array
    {
        // The database treats emails case-insensitively, so the rule must too; otherwise a duplicate slips past it.
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        /** @var array{name: string, email: string, phone?: string|null, employee_no?: string|null, user_code?: string|null, user_type: string, is_active?: bool|null, roles?: list<string>|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($managed?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'employee_no' => ['nullable', 'string', 'max:40', Rule::unique('users', 'employee_no')->ignore($managed?->id)],
            'user_code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'user_code')->ignore($managed?->id)],
            'user_type' => ['required', Rule::enum(UserType::class)],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'max:60'],
        ]);

        $roles = array_values($data['roles'] ?? []);

        // Leaders and engineers are created and changed under Teknisi, never through this form.
        if (array_intersect($roles, FieldAccount::roles()) !== [] || $data['user_type'] === UserType::Technician->value) {
            throw ValidationException::withMessages(['roles' => 'Leader dan teknisi diatur lewat menu Teknisi.']);
        }

        return [...$data, 'is_active' => (bool) ($data['is_active'] ?? true), 'roles' => $roles];
    }

    /**
     * @return array<int, array{name: string, label: string, privileged: bool}>
     */
    private function roleOptions(): array
    {
        return Role::query()->orderBy('id')->get()->map(fn (Role $role): array => [
            'name' => $role->name,
            'label' => (string) ($role->getAttribute('label') ?? $role->name),
            'privileged' => $role->name === RoleName::SuperAdmin->value,
        ])->values()->all();
    }

    /**
     * @return list<array{name: string, label: string, privileged: bool}>
     */
    private function officeRoleOptions(): array
    {
        return array_values(array_filter($this->roleOptions(), static fn (array $option): bool => ! in_array($option['name'], FieldAccount::roles(), true)));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function officeTypeOptions(): array
    {
        return array_values(array_filter($this->typeOptions(), static fn (array $option): bool => $option['value'] !== UserType::Technician->value));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return array_map(static fn (UserType $type): array => ['value' => $type->value, 'label' => $type->label()], UserType::cases());
    }
}
