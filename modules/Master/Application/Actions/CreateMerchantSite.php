<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Merchant;
use Modules\Master\Domain\Models\MerchantSite;

final class CreateMerchantSite
{
    /**
     * @param  array{merchant_id: int, location_id: int, name?: string|null, address: string, latitude?: string|float|null, longitude?: string|float|null, pic_name?: string|null, pic_phone?: string|null, is_active?: bool}  $data
     */
    public function handle(array $data): MerchantSite
    {
        self::assertArea($data['location_id'], $data['merchant_id']);

        return MerchantSite::query()->create($data);
    }

    /**
     * A site is covered by exactly one managed area, the lowest level of the location tree, and that area must
     * work for the merchant's vendor (or for every vendor).
     */
    public static function assertArea(int $locationId, int $merchantId): void
    {
        $location = Location::query()->find($locationId);

        if (! $location instanceof Location || $location->type !== LocationType::Area) {
            throw ValidationException::withMessages(['location_id' => 'Site harus berada di sebuah Area Kelolaan.']);
        }

        $vendorId = Merchant::query()->whereKey($merchantId)->value('vendor_id');

        if ($location->vendor_id !== null && $vendorId !== null && $location->vendor_id !== (int) $vendorId) {
            throw ValidationException::withMessages(['location_id' => 'Area Kelolaan ini milik vendor lain.']);
        }
    }
}
