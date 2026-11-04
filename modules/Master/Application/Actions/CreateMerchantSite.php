<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\MerchantSite;

final class CreateMerchantSite
{
    /**
     * @param  array{merchant_id: int, location_id: int, name?: string|null, address: string, latitude?: string|float|null, longitude?: string|float|null, pic_name?: string|null, pic_phone?: string|null, is_active?: bool}  $data
     */
    public function handle(array $data): MerchantSite
    {
        self::assertServicePoint($data['location_id']);

        return MerchantSite::query()->create($data);
    }

    /**
     * A site is covered by exactly one service point, the lowest level of the location tree.
     */
    public static function assertServicePoint(int $locationId): void
    {
        $location = Location::query()->find($locationId);

        if (! $location instanceof Location || $location->type !== LocationType::ServicePoint) {
            throw ValidationException::withMessages(['location_id' => 'Site harus berada di sebuah Service Point.']);
        }
    }
}
