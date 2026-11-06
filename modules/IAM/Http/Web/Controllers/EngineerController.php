<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Web\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Application\Actions\SaveUser;
use Modules\IAM\Application\Actions\SaveUserPhoto;
use Modules\IAM\Domain\Enums\EmploymentStatus;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\FieldAccount;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ModuleController;

/**
 * Leaders and engineers: the people who work in the areas. Every one of them is registered with the same
 * details (code, name, username, email, employment, NIK, phone, address, password, photo). Once saved, the
 * next step is always their coverage, so the form hands over to the screen that sets vendors and areas.
 * The list lives in UserController::engineers().
 */
final class EngineerController extends ModuleController
{
    use PasswordValidationRules;

    public function create(): Response
    {
        $this->allow(Permissions::IAM_USER_CREATE);

        return Inertia::render('iam/engineers/Form', [
            'managed' => null,
            'roles' => $this->roleOptions(),
            'employment' => EmploymentStatus::options(),
            'passwordHint' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, SaveUser $users, SaveUserPhoto $photos): RedirectResponse
    {
        $this->allow(Permissions::IAM_USER_CREATE);

        $data = $this->validated($request, null);
        $actor = $this->user();

        // The account and its picture are saved together: a picture that cannot be used leaves no half-made account.
        $user = $this->translatingErrors([], fn (): User => DB::transaction(function () use ($users, $photos, $request, $data, $actor): User {
            $created = $users->handle(null, $data, $actor)['user'];
            $photos->handle($created, $request->file('photo'), $actor);

            return $created;
        }));

        $this->success('Akun dibuat. Sekarang atur vendor dan area kerjanya.');

        return to_route('master.user-scopes.edit', $user->ulid);
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
                'username' => $managedUser->username,
                'email' => $managedUser->email,
                'phone' => $managedUser->phone,
                'employment_status' => $managedUser->employment_status?->value,
                'nik' => $managedUser->nik,
                'address' => $managedUser->address,
                'user_code' => $managedUser->user_code,
                'photo_full_url' => $managedUser->photo_path === null ? null : '/iam/users/'.$managedUser->ulid.'/photo',
                'role' => $managedUser->roles()->whereIn('name', FieldAccount::roles())->value('name'),
                'is_active' => $managedUser->is_active,
                'is_self' => $managedUser->is($this->user()),
            ],
            'roles' => $this->roleOptions(),
            'employment' => EmploymentStatus::options(),
            'passwordHint' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(Request $request, User $managedUser, SaveUser $users, SaveUserPhoto $photos): RedirectResponse
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        abort_unless(FieldAccount::is($managedUser), 404);

        $data = $this->validated($request, $managedUser);
        $actor = $this->user();

        $this->translatingErrors([], fn () => DB::transaction(function () use ($users, $photos, $request, $managedUser, $data, $actor): void {
            $users->handle($managedUser, $data, $actor);

            if ($request->hasFile('photo')) {
                $photos->handle($managedUser, $request->file('photo'), $actor);
            }
        }));

        $this->success('Data disimpan.');

        return to_route('iam.engineers.edit', $managedUser->ulid);
    }

    /**
     * @return array{name: string, email: string, phone: string, employee_no: null, user_code: string, username: string, employment_status: string, nik: string, address: string, password: string|null, user_type: string, is_active: bool, roles: list<string>}
     */
    private function validated(Request $request, ?User $managed): array
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'username' => mb_strtolower(trim((string) $request->input('username'))),
        ]);

        $creating = ! $managed instanceof User;
        $password = $this->passwordRules();

        /** @var array{name: string, username: string, email: string, phone: string, employment_status: string, nik: string, address: string, user_code: string, role: string, password?: string|null, is_active?: bool|null} $data */
        $data = $request->validate([
            'user_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'user_code')->ignore($managed?->id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($managed?->id)->whereNull('deleted_at')],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($managed?->id)],
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'nik' => ['required', 'digits:16', Rule::unique('users', 'nik')->ignore($managed?->id)->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+()\\-\\s]{6,20}$/'],
            'address' => ['required', 'string', 'max:500'],
            // A password is required for a new account; when changing an account, leave it empty to keep the old one.
            'password' => $creating ? $password : ['nullable', ...array_slice($password, 1)],
            'photo' => [$creating ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp'],
            'role' => ['required', Rule::in(FieldAccount::roles())],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'user_code' => 'kode user', 'name' => 'nama lengkap', 'username' => 'username', 'employment_status' => 'status karyawan',
            'nik' => 'NIK', 'phone' => 'nomor telepon', 'address' => 'alamat', 'password' => 'password', 'photo' => 'foto profil',
        ]);

        // A person cannot change their own role; the form sends the current one.
        if ($managed instanceof User && $managed->is($this->user()) && ! $managed->hasRole($data['role'])) {
            throw ValidationException::withMessages(['role' => 'Anda tidak dapat mengubah peran Anda sendiri.']);
        }

        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'employee_no' => null,
            'user_code' => $data['user_code'],
            'username' => $data['username'],
            'employment_status' => $data['employment_status'],
            'nik' => $data['nik'],
            'address' => $data['address'],
            'password' => ($data['password'] ?? '') !== '' ? $data['password'] : null,
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
