<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Enums;

enum AssetStatus: string
{
    case InStock = 'in_stock';
    case InTransit = 'in_transit';
    case WithTechnician = 'with_technician';
    case Installed = 'installed';
    case InRepair = 'in_repair';
    case Retired = 'retired';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'Di gudang',
            self::InTransit => 'Menunggu serah-terima',
            self::WithTechnician => 'Dipegang teknisi',
            self::Installed => 'Terpasang',
            self::InRepair => 'Diperbaiki',
            self::Retired => 'Dipensiunkan',
            self::Lost => 'Hilang',
        };
    }
}
