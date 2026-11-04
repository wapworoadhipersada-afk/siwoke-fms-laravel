<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use Modules\Asset\Domain\Enums\AssetCondition;
use Modules\Asset\Domain\Models\Asset;

/**
 * Edits what is not a movement: the condition and the asset tag. Where the asset is and who holds it
 * can only change through the movement engine.
 */
final class UpdateAsset
{
    public function handle(Asset $asset, AssetCondition $condition, ?string $assetTag): Asset
    {
        $asset->fill(['condition' => $condition, 'asset_tag' => $assetTag !== null && trim($assetTag) !== '' ? trim($assetTag) : null])->save();

        return $asset;
    }
}
