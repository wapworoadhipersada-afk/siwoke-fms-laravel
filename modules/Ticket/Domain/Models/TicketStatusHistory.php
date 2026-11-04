<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;

/**
 * One row of the ticket history. The database refuses UPDATE and DELETE on this table.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $visit_id
 * @property TicketEvent $event
 * @property TicketStatus|null $from_status
 * @property TicketStatus $to_status
 * @property int|null $actor_id
 * @property string $actor_type
 * @property string|null $reason
 * @property array<string, mixed> $meta
 * @property string|null $ip
 * @property int|null $device_id
 * @property string|null $client_uuid
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
#[Fillable(['ticket_id', 'visit_id', 'event', 'from_status', 'to_status', 'actor_id', 'actor_type', 'reason', 'meta', 'ip', 'device_id', 'client_uuid', 'occurred_at'])]
class TicketStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => TicketEvent::class,
            'from_status' => TicketStatus::class,
            'to_status' => TicketStatus::class,
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
