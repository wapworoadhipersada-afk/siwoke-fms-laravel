<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Modules\Master\Domain\Models\MerchantSite;

/**
 * The merchant of a site never changes. Terminals on the site follow it when its service point changes.
 */
final class UpdateMerchantSite
{
    /**
     * @param  array{location_id?: int, name?: string|null, address?: string, latitude?: string|float|null, longitude?: string|float|null, pic_name?: string|null, pic_phone?: string|null, is_active?: bool}  $data
     */
    public function handle(MerchantSite $site, array $data): MerchantSite
    {
        if (isset($data['location_id']) && $data['location_id'] !== $site->location_id) {
            CreateMerchantSite::assertServicePoint($data['location_id']);
        }

        $site->fill($data)->save();

        return $site;
    }
}
