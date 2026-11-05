<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetMovement;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Modules\Ticket\Domain\Enums\AssetLineAction;

/**
 * One asset used on a visit: installed, pulled out, or a consumable used up. It is a plan until the visit
 * finishes; only then is it applied to the asset ledger, in the same transaction as the close.
 *
 * @property int $id
 * @property string $ulid
 * @property int $ticket_id
 * @property int $visit_id
 * @property AssetLineAction $action
 * @property int|null $asset_id
 * @property int $asset_model_id
 * @property int $owner_vendor_id
 * @property int $quantity
 * @property string|null $notes
 * @property string|null $client_uuid
 * @property int $created_by
 * @property int|null $applied_movement_id
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TicketAssetLine extends Model
{
    use HasPublicUlid;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    public function isApplied(): bool
    {
        return $this->applied_movement_id !== null;
    }

    public function isPending(): bool
    {
        return $this->applied_movement_id === null && $this->voided_at === null;
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<TicketVisit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(TicketVisit::class, 'visit_id');
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
    public function assetModel(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class);
    }

    /**
     * @return BelongsTo<AssetMovement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(AssetMovement::class, 'applied_movement_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AssetLineAction::class,
            'quantity' => 'integer',
            'voided_at' => 'datetime',
        ];
    }
}
