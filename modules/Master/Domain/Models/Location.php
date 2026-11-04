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
use Modules\Master\Application\Services\LocationAccess;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Province → City → Area → Service Point, stored as an adjacency list plus a materialized path.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $parent_id
 * @property LocationType $type
 * @property string $code
 * @property string $name
 * @property string $path
 * @property int $depth
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['parent_id', 'type', 'code', 'name', 'address', 'latitude', 'longitude', 'is_active'])]
class Location extends Model
{
    use HasPublicUlid, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        // The path embeds the row's own id, which only exists once the row has been inserted.
        static::created(static function (Location $location): void {
            $location->refreshPath();
        });
    }

    /**
     * Recompute path and depth from the parent. Does not touch descendants; see UpdateLocation.
     */
    public function refreshPath(): void
    {
        $parent = $this->parent_id === null ? null : static::query()->withTrashed()->find($this->parent_id);

        $this->forceFill([
            'path' => ($parent->path ?? '/').$this->id.'/',
            'depth' => ($parent->depth ?? 0) + 1,
        ])->saveQuietly();
    }

    public function isDescendantOf(self $other): bool
    {
        return $this->id !== $other->id && str_starts_with($this->path, $other->path);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MerchantSite, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(MerchantSite::class);
    }

    /**
     * Locations the user may see: their assigned subtrees, or everything for unrestricted roles.
     *
     * @param  Builder<Location>  $query
     * @return Builder<Location>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $access = app(LocationAccess::class);

        if ($access->isUnrestricted($user)) {
            return $query;
        }

        $paths = $access->rootPaths($user);

        if ($paths === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($paths): void {
            foreach ($paths as $path) {
                $query->orWhere('locations.path', 'like', $path.'%');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('master')
            ->logOnly(['parent_id', 'type', 'code', 'name', 'address', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_active' => 'boolean',
            'depth' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
