<?php

declare(strict_types=1);

namespace Modules\Master\Tests\Support;

use Modules\Master\Application\Actions\CreateLocation;
use Modules\Master\Application\Actions\CreateMerchant;
use Modules\Master\Application\Actions\CreateMerchantSite;
use Modules\Master\Application\Actions\CreateTerminal;
use Modules\Master\Application\Actions\CreateVendor;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Merchant;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Domain\Models\Vendor;

/**
 * Builds valid master data through the real actions, so fixtures obey the same rules as production.
 */
final class MasterFixtures
{
    private static int $sequence = 0;

    public static function next(): int
    {
        return ++self::$sequence;
    }

    public static function vendor(string $code = 'BRI'): Vendor
    {
        return app(CreateVendor::class)->handle(['code' => $code, 'name' => "Bank {$code}"]);
    }

    public static function location(LocationType $type, ?Location $parent = null, ?string $code = null): Location
    {
        $code ??= strtoupper($type->value).'-'.self::next();

        return app(CreateLocation::class)->handle([
            'type' => $type,
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => "{$type->label()} {$code}",
        ]);
    }

    /**
     * A full chain Province → City → Area → Service Point.
     *
     * @return array{province: Location, city: Location, area: Location, service_point: Location}
     */
    public static function chain(): array
    {
        $province = self::location(LocationType::Province);
        $city = self::location(LocationType::City, $province);
        $area = self::location(LocationType::Area, $city);
        $servicePoint = self::location(LocationType::ServicePoint, $area);

        return ['province' => $province, 'city' => $city, 'area' => $area, 'service_point' => $servicePoint];
    }

    public static function merchant(Vendor $vendor, ?string $code = null): Merchant
    {
        $code ??= 'MID-'.self::next();

        return app(CreateMerchant::class)->handle(['vendor_id' => $vendor->id, 'merchant_code' => $code, 'name' => "Toko {$code}"]);
    }

    public static function site(Merchant $merchant, Location $servicePoint): MerchantSite
    {
        return app(CreateMerchantSite::class)->handle([
            'merchant_id' => $merchant->id,
            'location_id' => $servicePoint->id,
            'address' => 'Jl. Contoh No. '.self::next(),
        ]);
    }

    public static function terminal(MerchantSite $site, ?string $tid = null): Terminal
    {
        return app(CreateTerminal::class)->handle([
            'merchant_site_id' => $site->id,
            'tid' => $tid ?? 'TID'.str_pad((string) self::next(), 5, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * A terminal standing in the given service point, under a fresh merchant of the given vendor.
     */
    public static function terminalIn(Vendor $vendor, Location $servicePoint): Terminal
    {
        return self::terminal(self::site(self::merchant($vendor), $servicePoint));
    }
}
