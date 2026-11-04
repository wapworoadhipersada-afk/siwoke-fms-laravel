<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A product type, e.g. "Verifone VX520". Individual units of it are Assets.
 *
 * @property int $id
 * @property string $ulid
 * @property int $asset_category_id
 * @property string $brand
 * @property string $model_name
 * @property array<string, mixed> $spec
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['asset_category_id', 'brand', 'model_name', 'spec', 'is_active'])]
class AssetModel extends Model
{
    use HasPublicUlid, LogsActivity, SoftDeletes;

    /**
     * Database default, mirrored so a model created in code is usable before it is reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    public function displayName(): string
    {
        return "{$this->brand} {$this->model_name}";
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('asset')
            ->logOnly(['asset_category_id', 'brand', 'model_name', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
