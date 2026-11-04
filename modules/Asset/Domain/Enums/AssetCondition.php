<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Enums;

enum AssetCondition: string
{
    case Good = 'good';
    case Damaged = 'damaged';
    case NeedsRepair = 'needs_repair';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Baik',
            self::Damaged => 'Rusak',
            self::NeedsRepair => 'Perlu perbaikan',
        };
    }
}
