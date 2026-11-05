<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TicketWorkflow;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * Cancels a ticket that has not reached the merchant yet (vendor withdrew it, duplicate, mistake).
 * Once the technician has arrived, the visit must be closed instead.
 */
final class CancelTicket
{
    public function __construct(private readonly TicketWorkflow $workflow) {}

    public function handle(Ticket $ticket, ?User $actor, string $reason): Ticket
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($ticket, $actor, $reason): Ticket {
            $current = $this->workflow->lock($ticket);
            $at = now();

            $this->workflow->cancelOpenVisit($current, $at, $reason);
            $current->forceFill(['technician_id' => null]);

            $this->workflow->transition($current, TicketStatus::Cancelled, TicketEvent::Cancelled, $actor, new ActionContext, $at, null, $reason);

            return $current;
        });
    }
}
