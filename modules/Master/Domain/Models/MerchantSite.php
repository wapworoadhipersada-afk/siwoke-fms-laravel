<?php

declare(strict_types=1);

namespace Modules\Master\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A physical outlet of a merchant, attached to the service point that covers it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $location_id
 * @property string|null $name
 * @property string $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $pic_name
 * @property string|null $pic_phone
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['merchant_id', 'location_id', 'name', 'address', 'latitude', 'longitude', 'pic_name', 'pic_phone', 'is_active'])]
class MerchantSite extends Model
{
    use HasPublicUlid, LogsActivity, SoftDeletes;

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<Terminal, $this>
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    /**
     * @param  Builder<MerchantSite>  $query
     * @return Builder<MerchantSite>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('merchant_sites.location_id', Location::query()->visibleTo($user)->select('locations.id'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('master')
            ->logOnly(['merchant_id', 'location_id', 'name', 'address', 'pic_name', 'pic_phone', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
