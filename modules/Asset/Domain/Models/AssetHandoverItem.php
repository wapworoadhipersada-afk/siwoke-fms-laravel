<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Asset\Domain\Enums\AssetStatus;

/**
 * @property int $id
 * @property int $handover_id
 * @property int|null $asset_id
 * @property int $asset_model_id
 * @property int $owner_vendor_id
 * @property int $quantity
 * @property AssetStatus|null $from_status
 */
#[Fillable(['handover_id', 'asset_id', 'asset_model_id', 'owner_vendor_id', 'quantity', 'from_status'])]
class AssetHandoverItem extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<AssetHandover, $this>
     */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(AssetHandover::class, 'handover_id');
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_status' => AssetStatus::class];
    }
}
