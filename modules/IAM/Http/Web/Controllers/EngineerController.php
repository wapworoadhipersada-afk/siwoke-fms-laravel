<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Web\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Application\Actions\SaveUser;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\FieldAccount;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ModuleController;

/**
 * Leaders and engineers: the people who work in the areas. Their form differs from an office account: a user
 * code is required, the role is one of Leader or Teknisi, and the next step is always their coverage (the
 * vendors and areas they work for). The list lives in UserController::engineers().
 */
final class EngineerController extends ModuleController
{
    public function create(): Response
    {
        $this->allow(Permissions::IAM_USER_CREATE);

        return Inertia::render('iam/engineers/Form', ['managed' => null, 'roles' => $this->roleOptions()]);
    }

    public function store(Request $request, SaveUser $action): Response
    {
        $this->allow(Permissions::IAM_USER_CREATE);

        $data = $this->validated($request, null);
        $result = $this->translatingErrors([], fn (): array => $action->handle(null, $data, $this->user()));

        return Inertia::render('iam/users/Credentials', [
            'account' => ['name' => $result['user']->name, 'email' => $result['user']->email],
            'password' => $result['password'],
            'reset' => false,
            'sectionTitle' => 'Engineer / Teknisi',
            'sectionHref' => route('iam.engineers.index', absolute: false),
            'coverageHref' => route('master.user-scopes.edit', $result['user']->ulid, absolute: false),
        ]);
    }

    public function edit(User $managedUser): Response|RedirectResponse
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        if (! FieldAccount::is($managedUser)) {
            return to_route('iam.users.edit', $managedUser->ulid);
        }

        return Inertia::render('iam/engineers/Form', [
            'managed' => [
                'ulid' => $managedUser->ulid,
                'name' => $managedUser->name,
                'email' => $managedUser->email,
                'phone' => $managedUser->phone,
                'employee_no' => $managedUser->employee_no,
                'user_code' => $managedUser->user_code,
                'photo_full_url' => $managedUser->photo_path === null ? null : '/iam/users/'.$managedUser->ulid.'/photo',
                'role' => $managedUser->roles()->whereIn('name', FieldAccount::roles())->value('name'),
                'is_active' => $managedUser->is_active,
                'is_self' => $managedUser->is($this->user()),
            ],
            'roles' => $this->roleOptions(),
        ]);
    }

    public function update(Request $request, User $managedUser, SaveUser $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        abort_unless(FieldAccount::is($managedUser), 404);

        $data = $this->validated($request, $managedUser);
        $this->translatingErrors([], fn (): array => $action->handle($managedUser, $data, $this->user()));
        $this->success('Data engineer disimpan.');

        return to_route('iam.engineers.edit', $managedUser->ulid);
    }

    /**
     * @return array{name: string, email: string, phone?: string|null, employee_no?: string|null, user_code: string, user_type: string, is_active: bool, roles: list<string>}
     */
    private function validated(Request $request, ?User $managed): array
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        /** @var array{name: string, email: string, phone?: string|null, employee_no?: string|null, user_code: string, role: string, is_active?: bool|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($managed?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'employee_no' => ['nullable', 'string', 'max:40', Rule::unique('users', 'employee_no')->ignore($managed?->id)],
            'user_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'user_code')->ignore($managed?->id)],
            'role' => ['required', Rule::in(FieldAccount::roles())],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // A person cannot change their own role or switch themselves off; the form sends the current values.
        if ($managed instanceof User && $managed->is($this->user()) && ! $managed->hasRole($data['role'])) {
            throw ValidationException::withMessages(['role' => 'Anda tidak dapat mengubah peran Anda sendiri.']);
        }

        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'employee_no' => $data['employee_no'] ?? null,
            'user_code' => $data['user_code'],
            // Engineers use the mobile app; a leader works in the office screens.
            'user_type' => $data['role'] === RoleName::Technician->value ? UserType::Technician->value : UserType::Staff->value,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'roles' => [$data['role']],
        ];
    }

    /**
     * @return list<array{name: string, label: string}>
     */
    private function roleOptions(): array
    {
        return [
            ['name' => RoleName::Technician->value, 'label' => RoleName::Technician->label()],
            ['name' => RoleName::Leader->value, 'label' => RoleName::Leader->label()],
        ];
    }
}
