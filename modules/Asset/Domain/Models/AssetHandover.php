<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Asset\Application\Services\HolderVisibility;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Shared\Domain\Concerns\HasPublicUlid;

/**
 * A custody change waiting for the receiving side to confirm. Nothing has moved yet; the assets are
 * only marked "in transit". Accepting it writes the ledger movement.
 *
 * @property int $id
 * @property string $ulid
 * @property MovementType $type
 * @property HandoverStatus $status
 * @property HolderType $from_holder_type
 * @property int|null $from_holder_id
 * @property HolderType $to_holder_type
 * @property int|null $to_holder_id
 * @property int $requested_by
 * @property int|null $resolved_by
 * @property Carbon $requested_at
 * @property Carbon|null $resolved_at
 * @property string|null $note
 * @property string|null $resolution_note
 * @property int|null $movement_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'status', 'from_holder_type', 'from_holder_id', 'to_holder_type', 'to_holder_id', 'requested_by', 'resolved_by', 'requested_at', 'resolved_at', 'note', 'resolution_note', 'movement_id'])]
class AssetHandover extends Model
{
    use HasPublicUlid;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending'];

    public function from(): Holder
    {
        return new Holder($this->from_holder_type, $this->from_holder_id);
    }

    public function to(): Holder
    {
        return new Holder($this->to_holder_type, $this->to_holder_id);
    }

    public function isPending(): bool
    {
        return $this->status === HandoverStatus::Pending;
    }

    /**
     * @return HasMany<AssetHandoverItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AssetHandoverItem::class, 'handover_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Handovers the user may see: those whose sending or receiving side is within their custody view.
     *
     * @param  Builder<AssetHandover>  $query
     * @return Builder<AssetHandover>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $visibility = app(HolderVisibility::class);

        if ($visibility->isUnrestricted($user)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($visibility, $user): void {
            $query->where(fn (Builder $query) => $visibility->custodyClauses($query, $user, 'asset_handovers.from_holder_type', 'asset_handovers.from_holder_id'))
                ->orWhere(fn (Builder $query) => $visibility->custodyClauses($query, $user, 'asset_handovers.to_holder_type', 'asset_handovers.to_holder_id'));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'status' => HandoverStatus::class,
            'from_holder_type' => HolderType::class,
            'to_holder_type' => HolderType::class,
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
