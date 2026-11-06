<?php

declare(strict_types=1);

namespace Modules\IAM\Domain;

use App\Models\User;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;

/**
 * Field accounts are the leaders and engineers who work in the areas. They have their own menu and their own
 * forms, apart from the office accounts under "Pengguna".
 */
final class FieldAccount
{
    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [RoleName::Leader->value, RoleName::Technician->value];
    }

    public static function is(User $user): bool
    {
        return $user->user_type === UserType::Technician || $user->roles->pluck('name')->intersect(self::roles())->isNotEmpty();
    }
}
