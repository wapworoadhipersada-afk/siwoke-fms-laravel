<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Enums;

enum UserType: string
{
    case Staff = 'staff';
    case Technician = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staf',
            self::Technician => 'Teknisi',
        };
    }
}
