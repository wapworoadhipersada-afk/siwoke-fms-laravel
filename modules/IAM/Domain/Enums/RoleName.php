<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super-admin';
    case Managerial = 'managerial';
    case Leader = 'leader';
    case Coordinator = 'coordinator';
    case OperationalAdmin = 'operational-admin';
    case Technician = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Managerial => 'Managerial',
            self::Leader => 'Leader',
            self::Coordinator => 'Koordinator',
            self::OperationalAdmin => 'Admin Operasional',
            self::Technician => 'Teknisi',
        };
    }
}
