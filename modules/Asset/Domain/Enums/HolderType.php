<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Enums;

enum HolderType: string
{
    case Warehouse = 'warehouse';
    case User = 'user';
    case Terminal = 'terminal';
    case External = 'external';

    /**
     * Only warehouses and technicians keep stock balances of consumables.
     */
    public function holdsStock(): bool
    {
        return $this === self::Warehouse || $this === self::User;
    }

    public function label(): string
    {
        return match ($this) {
            self::Warehouse => 'Gudang',
            self::User => 'Teknisi',
            self::Terminal => 'TID / merchant',
            self::External => 'Di luar (vendor, service center)',
        };
    }
}
