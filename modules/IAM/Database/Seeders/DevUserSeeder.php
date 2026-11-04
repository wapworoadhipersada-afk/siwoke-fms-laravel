<?php

declare(strict_types=1);

namespace Modules\IAM\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;

/**
 * One account per role for local development only. Refuses to run anywhere else.
 */
class DevUserSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('DevUserSeeder only runs in the local or testing environment; skipped.');

            return;
        }

        foreach (RoleName::cases() as $role) {
            $user = User::query()->firstOrNew(['email' => "{$role->value}@fms.test"]);

            $user->forceFill([
                'name' => $role->label(),
                'password' => Hash::make(self::PASSWORD),
                'user_type' => $role === RoleName::Technician ? UserType::Technician : UserType::Staff,
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();

            $user->syncRoles([$role->value]);
        }
    }
}
