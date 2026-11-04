<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Modules\IAM\Domain\Models\RefreshToken;
use Modules\Shared\Support\Audit;

final class RevokeAllUserSessions
{
    public function handle(User $user): void
    {
        $user->tokens()->delete();

        RefreshToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        Audit::record('auth', 'logout_all', 'Semua sesi mobile dicabut', $user, $user);
    }
}
