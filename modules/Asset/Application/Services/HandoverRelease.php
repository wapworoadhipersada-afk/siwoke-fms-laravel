<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Services;

use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetHandover;

/**
 * Puts the assets of a handover that did not go through back where they were. Consumables need
 * nothing: their reservation disappears as soon as the handover is no longer pending.
 */
final class HandoverRelease
{
    public function release(AssetHandover $handover): void
    {
        $handover->loadMissing('items');

        foreach ($handover->items as $item) {
            if ($item->asset_id === null) {
                continue;
            }

            $asset = Asset::query()->whereKey($item->asset_id)->lockForUpdate()->first();

            if (! $asset instanceof Asset || $asset->pending_handover_id !== $handover->id) {
                continue;
            }

            $asset->forceFill([
                'status' => $item->from_status ?? AssetStatus::InStock,
                'pending_handover_id' => null,
                'version' => $asset->version + 1,
            ])->save();
        }
    }
}
