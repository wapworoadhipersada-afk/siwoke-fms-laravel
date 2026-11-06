<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Shared\Support\Audit;

/**
 * Gives someone a new temporary password (shown once to the administrator) and ends their mobile sessions.
 * Only Super Admin may do this for a Super Admin.
 */
final class ResetUserPassword
{
    public function __construct(private readonly RevokeAllUserSessions $revoke) {}

    public function handle(User $user, User $actor): string
    {
        if ($user->hasRole(RoleName::SuperAdmin->value) && ! $actor->hasRole(RoleName::SuperAdmin->value)) {
            throw ValidationException::withMessages(['user' => 'Hanya Super Admin yang dapat mengatur ulang password Super Admin.']);
        }

        $password = Str::password(14, symbols: false);
        $user->forceFill(['password' => Hash::make($password)])->save();
        $this->revoke->handle($user);

        Audit::record('iam', 'password_reset', "Password diatur ulang: {$user->email}", $actor, $user);

        return $password;
    }
}
