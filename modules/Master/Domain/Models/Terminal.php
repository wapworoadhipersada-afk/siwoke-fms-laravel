<?php

declare(strict_types=1);

namespace Modules\Master\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Master\Domain\Enums\TerminalStatus;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The logical terminal identity (TID) a bank knows. The physical EDC machine that currently
 * serves it is an asset, linked later through asset installations.
 *
 * @property int $id
 * @property string $ulid
 * @property int $vendor_id
 * @property int $merchant_id
 * @property int $merchant_site_id
 * @property string $tid
 * @property TerminalStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['vendor_id', 'merchant_id', 'merchant_site_id', 'tid', 'status'])]
class Terminal extends Model
{
    use HasPublicUlid, LogsActivity, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<MerchantSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(MerchantSite::class, 'merchant_site_id');
    }

    /**
     * @param  Builder<Terminal>  $query
     * @return Builder<Terminal>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('terminals.merchant_site_id', MerchantSite::query()->visibleTo($user)->select('merchant_sites.id'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('master')
            ->logOnly(['vendor_id', 'merchant_id', 'merchant_site_id', 'tid', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TerminalStatus::class,
        ];
    }
}
