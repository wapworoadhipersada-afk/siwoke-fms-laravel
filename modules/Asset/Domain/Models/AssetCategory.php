<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Asset\Domain\Enums\TrackingMode;
use Modules\Shared\Domain\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $code
 * @property string $name
 * @property TrackingMode $tracking_mode
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'tracking_mode', 'is_active'])]
class AssetCategory extends Model
{
    use HasPublicUlid;

    /**
     * Database default, mirrored so a model created in code is usable before it is reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /**
     * @return HasMany<AssetModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AssetModel::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tracking_mode' => TrackingMode::class,
            'is_active' => 'boolean',
        ];
    }
}
