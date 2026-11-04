<?php

declare(strict_types=1);

namespace Modules\Master\Domain\Enums;

enum LocationType: string
{
    case Province = 'province';
    case City = 'city';
    case Area = 'area';
    case ServicePoint = 'service_point';

    /**
     * The type a parent must have, or null for the root level.
     */
    public function parentType(): ?self
    {
        return match ($this) {
            self::Province => null,
            self::City => self::Province,
            self::Area => self::City,
            self::ServicePoint => self::Area,
        };
    }

    /**
     * @return 1|2|3|4
     */
    public function depth(): int
    {
        return match ($this) {
            self::Province => 1,
            self::City => 2,
            self::Area => 3,
            self::ServicePoint => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Province => 'Provinsi',
            self::City => 'Kota/Kabupaten',
            self::Area => 'Area',
            self::ServicePoint => 'Service Point',
        };
    }
}
