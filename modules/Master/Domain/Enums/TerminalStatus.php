<?php

declare(strict_types=1);

namespace Modules\Master\Domain\Enums;

enum TerminalStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Inactive => 'Nonaktif',
            self::Removed => 'Ditarik',
        };
    }
}
