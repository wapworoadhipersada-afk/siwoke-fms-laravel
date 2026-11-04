<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Enums;

enum TrackingMode: string
{
    case Serialized = 'serialized';
    case Quantity = 'quantity';

    public function label(): string
    {
        return match ($this) {
            self::Serialized => 'Per unit (nomor seri)',
            self::Quantity => 'Per jumlah (stok)',
        };
    }
}
