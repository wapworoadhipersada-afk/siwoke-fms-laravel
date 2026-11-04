<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Shared\Domain\Concerns\HasPublicUlid;

/**
 * One row of the append-only ledger. The database refuses UPDATE and DELETE on this table.
 *
 * @property int $id
 * @property string $ulid
 * @property string $movement_no
 * @property MovementType $type
 * @property HolderType|null $from_holder_type
 * @property int|null $from_holder_id
 * @property HolderType|null $to_holder_type
 * @property int|null $to_holder_id
 * @property int $actor_id
 * @property int|null $handover_id
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $notes
 * @property Carbon $occurred_at
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $device_id
 * @property array<string, mixed> $meta
 * @property Carbon|null $created_at
 */
#[Fillable(['movement_no', 'type', 'from_holder_type', 'from_holder_id', 'to_holder_type', 'to_holder_id', 'actor_id', 'handover_id', 'reference_type', 'reference_id', 'notes', 'occurred_at', 'latitude', 'longitude', 'device_id', 'meta'])]
class AssetMovement extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

    public function from(): Holder
    {
        return new Holder($this->from_holder_type ?? HolderType::External, $this->from_holder_id);
    }

    public function to(): Holder
    {
        return new Holder($this->to_holder_type ?? HolderType::External, $this->to_holder_id);
    }

    /**
     * @return HasMany<AssetMovementItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AssetMovementItem::class, 'movement_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<AssetHandover, $this>
     */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(AssetHandover::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'from_holder_type' => HolderType::class,
            'to_holder_type' => HolderType::class,
            'occurred_at' => 'datetime',
            'meta' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
