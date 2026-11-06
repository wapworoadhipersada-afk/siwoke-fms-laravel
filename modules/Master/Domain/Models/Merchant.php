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
use Modules\Master\Application\Services\VendorAccess;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $ulid
 * @property int $vendor_id
 * @property string $merchant_code
 * @property string $name
 * @property string|null $owner_name
 * @property string|null $phone
 * @property string|null $category
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['vendor_id', 'merchant_code', 'name', 'owner_name', 'phone', 'category'])]
class Merchant extends Model
{
    use HasPublicUlid, LogsActivity, SoftDeletes;

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<MerchantSite, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(MerchantSite::class);
    }

    /**
     * @return HasMany<Terminal, $this>
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    /**
     * A merchant is visible through its sites, so a restricted user sees only merchants operating in their area.
     * A merchant that has no site yet is visible to everyone who may see merchants, otherwise the person
     * who just created it could not reach it to add the first site.
     *
     * @param  Builder<Merchant>  $query
     * @return Builder<Merchant>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query = app(VendorAccess::class)->constrain($query, $user, 'merchants.vendor_id');

        return $query->where(function (Builder $query) use ($user): void {
            $query->whereIn('merchants.id', MerchantSite::query()->visibleTo($user)->select('merchant_sites.merchant_id'))
                ->orDoesntHave('sites');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('master')
            ->logOnly(['vendor_id', 'merchant_code', 'name', 'owner_name', 'phone', 'category'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
