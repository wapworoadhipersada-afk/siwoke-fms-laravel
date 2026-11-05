<?php

declare(strict_types=1);

namespace Modules\Integration\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Shared\Domain\Concerns\HasPublicUlid;

/**
 * A status change waiting to be (or already) told to a vendor. 'dead' means every retry failed and a person must look.
 *
 * @property int $id
 * @property string $ulid
 * @property int $client_id
 * @property int $vendor_id
 * @property int $ticket_id
 * @property int $history_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OutboundEvent extends Model
{
    use HasPublicUlid;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const DEAD = 'dead';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => self::PENDING, 'attempts' => 0];

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<IntegrationClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class, 'client_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
