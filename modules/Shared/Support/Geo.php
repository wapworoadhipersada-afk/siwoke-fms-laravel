<?php

declare(strict_types=1);

namespace Modules\Shared\Support;

final class Geo
{
    private const EARTH_RADIUS_METERS = 6_371_000;

    /**
     * Great-circle distance between two coordinates, in whole meters (haversine).
     */
    public static function distanceMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): int
    {
        $dLat = deg2rad($latitudeB - $latitudeA);
        $dLon = deg2rad($longitudeB - $longitudeA);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($dLon / 2) ** 2;

        return (int) round(2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a))));
    }
}
