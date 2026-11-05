<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Document\Domain\Concerns\InteractsWithAttachments;
use Modules\Document\Domain\Contracts\HasAttachments;
use Modules\IAM\Domain\Permissions;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Enums\VisitOutcome;

/**
 * One attempt at a ticket. A pending ticket that is rescheduled gets a new visit, so the timestamps
 * of the first attempt are never overwritten by the second.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $visit_no
 * @property int $technician_id
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $departed_at
 * @property Carbon|null $arrived_at
 * @property Carbon|null $handling_started_at
 * @property Carbon|null $closed_at
 * @property VisitOutcome|null $outcome
 * @property int|null $reason_code_id
 * @property string|null $notes
 * @property string|null $arrival_latitude
 * @property string|null $arrival_longitude
 * @property int|null $arrival_distance_m
 * @property string|null $close_latitude
 * @property string|null $close_longitude
 * @property array<string, mixed> $result_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['ticket_id', 'visit_no', 'technician_id', 'scheduled_at'])]
class TicketVisit extends Model implements HasAttachments
{
    use InteractsWithAttachments;

    /**
     * The technician of an open visit may add evidence from the moment they accept until they close it.
     * A user with the close-override permission may do it for them, like any other action on their behalf.
     */
    public function canAttachFiles(User $user): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        $status = $this->ticket->status;

        if (! in_array($status, [TicketStatus::Accepted, TicketStatus::OnTheWay, TicketStatus::Arrived, TicketStatus::Handling], true)) {
            return false;
        }

        return $this->technician_id === $user->id || $user->can(Permissions::TICKET_CLOSE_OVERRIDE);
    }

    /**
     * Anyone who may see the ticket may see its evidence.
     */
    public function canViewAttachments(User $user): bool
    {
        return $user->can(Permissions::TICKET_VIEW)
            && Ticket::query()->visibleTo($user)->whereKey($this->ticket_id)->exists();
    }

    public function isOpen(): bool
    {
        return $this->outcome === null;
    }

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
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * @return BelongsTo<ReasonCode, $this>
     */
    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'accepted_at' => 'datetime',
            'departed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'handling_started_at' => 'datetime',
            'closed_at' => 'datetime',
            'outcome' => VisitOutcome::class,
            'arrival_latitude' => 'decimal:7',
            'arrival_longitude' => 'decimal:7',
            'close_latitude' => 'decimal:7',
            'close_longitude' => 'decimal:7',
            'result_data' => 'array',
        ];
    }
}
