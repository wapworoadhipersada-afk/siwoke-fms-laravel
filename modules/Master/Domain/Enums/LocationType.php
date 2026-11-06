<?php

declare(strict_types=1);

namespace Modules\Master\Domain\Enums;

/**
 * The three levels of coverage: a province, the service points inside it, and the managed areas each
 * service point looks after. Merchant sites attach to the lowest level, the managed area.
 */
enum LocationType: string
{
    case Province = 'province';
    case ServicePoint = 'service_point';
    case Area = 'area';

    /**
     * The type a parent must have, or null for the root level.
     */
    public function parentType(): ?self
    {
        return match ($this) {
            self::Province => null,
            self::ServicePoint => self::Province,
            self::Area => self::ServicePoint,
        };
    }

    /**
     * @return 1|2|3
     */
    public function depth(): int
    {
        return match ($this) {
            self::Province => 1,
            self::ServicePoint => 2,
            self::Area => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Province => 'Provinsi',
            self::ServicePoint => 'Service Point',
            self::Area => 'Area Kelolaan',
        };
    }
}
