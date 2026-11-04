<?php

declare(strict_types=1);

namespace Modules\Asset\Application\DTOs;

use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Master\Domain\Models\Vendor;

/**
 * One line of a movement: either a single serialized asset, or a quantity of a consumable.
 */
final readonly class MoveItem
{
    public function __construct(
        public ?int $assetId,
        public int $assetModelId,
        public int $ownerVendorId,
        public int $quantity = 1,
    ) {}

    public static function asset(Asset $asset): self
    {
        return new self($asset->id, $asset->asset_model_id, $asset->owner_vendor_id);
    }

    public static function stock(AssetModel|int $model, Vendor|int $vendor, int $quantity): self
    {
        return new self(
            null,
            $model instanceof AssetModel ? $model->id : $model,
            $vendor instanceof Vendor ? $vendor->id : $vendor,
            $quantity,
        );
    }

    public function isSerialized(): bool
    {
        return $this->assetId !== null;
    }
}
