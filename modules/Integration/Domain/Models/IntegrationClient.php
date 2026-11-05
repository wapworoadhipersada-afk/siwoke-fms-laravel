<?php

declare(strict_types=1);

namespace Modules\Integration\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The credentials one vendor system uses to call us, and where we call it back. The secret and the webhook
 * secret are stored encrypted and are never shown again after creation or rotation.
 *
 * @property int $id
 * @property string $ulid
 * @property int $vendor_id
 * @property string $name
 * @property string $key_id
 * @property string $secret
 * @property list<string> $allowed_ips
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property bool $is_active
 * @property Carbon|null $last_used_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class IntegrationClient extends Model
{
    use HasPublicUlid, LogsActivity, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['secret', 'webhook_secret'];

    public function getActivitylogOptions(): LogOptions
    {
        // Secrets are deliberately not logged: not even encrypted.
        return LogOptions::defaults()
            ->useLogName('integration')
            ->logOnly(['vendor_id', 'name', 'key_id', 'allowed_ips', 'webhook_url', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'allowed_ips' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }
}
