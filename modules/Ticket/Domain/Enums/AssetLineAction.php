<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

/**
 * What the technician did with an asset during a visit.
 */
enum AssetLineAction: string
{
    case Install = 'install';
    case Uninstall = 'uninstall';
    case Consume = 'consume';

    public function label(): string
    {
        return match ($this) {
            self::Install => 'Dipasang',
            self::Uninstall => 'Ditarik',
            self::Consume => 'Dipakai habis',
        };
    }
}
