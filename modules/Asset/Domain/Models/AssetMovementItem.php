<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $movement_id
 * @property int|null $asset_id
 * @property int $asset_model_id
 * @property int $owner_vendor_id
 * @property int $quantity
 */
#[Fillable(['movement_id', 'asset_id', 'asset_model_id', 'owner_vendor_id', 'quantity'])]
class AssetMovementItem extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<AssetMovement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(AssetMovement::class, 'movement_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<AssetModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'asset_model_id');
    }
}
