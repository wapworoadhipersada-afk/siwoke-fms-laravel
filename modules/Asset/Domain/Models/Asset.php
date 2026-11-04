<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Asset\Application\Services\HolderVisibility;
use Modules\Asset\Domain\Enums\AssetCondition;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One tracked unit (an EDC machine, a SIM or SAM card). Where it is and who holds it right now is the
 * snapshot in `status`, `holder_type` and `holder_id`; the movement ledger is the history behind it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $asset_model_id
 * @property int $owner_vendor_id
 * @property string $serial_number
 * @property string|null $asset_tag
 * @property AssetCondition $condition
 * @property AssetStatus $status
 * @property HolderType $holder_type
 * @property int|null $holder_id
 * @property int|null $pending_handover_id
 * @property int|null $last_movement_id
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['asset_model_id', 'owner_vendor_id', 'serial_number', 'asset_tag', 'condition'])]
class Asset extends Model
{
    use HasPublicUlid, LogsActivity, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'condition' => 'good',
        'status' => 'in_stock',
    ];

    public function holder(): Holder
    {
        return new Holder($this->holder_type, $this->holder_id);
    }

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
     * @return BelongsTo<AssetHandover, $this>
     */
    public function pendingHandover(): BelongsTo
    {
        return $this->belongsTo(AssetHandover::class, 'pending_handover_id');
    }

    /**
     * @return HasMany<AssetMovementItem, $this>
     */
    public function movementItems(): HasMany
    {
        return $this->hasMany(AssetMovementItem::class);
    }

    /**
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(HolderVisibility::class)->constrain($query, $user, 'assets.holder_type', 'assets.holder_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Status and holder changes are recorded as movements in the ledger, not here.
        return LogOptions::defaults()
            ->useLogName('asset')
            ->logOnly(['serial_number', 'asset_tag', 'condition'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => AssetCondition::class,
            'status' => AssetStatus::class,
            'holder_type' => HolderType::class,
        ];
    }
}
