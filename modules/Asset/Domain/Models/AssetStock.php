<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Asset\Application\Services\HolderVisibility;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\Vendor;

/**
 * The balance of one consumable (thermal paper, chargers) held by one warehouse or technician for one vendor.
 *
 * @property int $id
 * @property int $asset_model_id
 * @property int $owner_vendor_id
 * @property HolderType $holder_type
 * @property int $holder_id
 * @property int $quantity
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['asset_model_id', 'owner_vendor_id', 'holder_type', 'holder_id', 'quantity'])]
class AssetStock extends Model
{
    /**
     * @return BelongsTo<AssetModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'asset_model_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'owner_vendor_id');
    }

    /**
     * @param  Builder<AssetStock>  $query
     * @return Builder<AssetStock>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query = app(VendorAccess::class)->constrain($query, $user, 'asset_stocks.owner_vendor_id');

        return app(HolderVisibility::class)->constrain($query, $user, 'asset_stocks.holder_type', 'asset_stocks.holder_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'holder_type' => HolderType::class,
            'quantity' => 'integer',
        ];
    }
}
