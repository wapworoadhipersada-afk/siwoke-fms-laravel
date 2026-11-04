<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TicketRelease;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * The technician refuses a job they were given, with a reason. It goes back to the unassigned pool.
 */
final class RejectTicket
{
    public function __construct(private readonly TicketRelease $release) {}

    public function handle(Ticket $ticket, User $technician, string $reason, ?ActionContext $context = null): Ticket
    {
        return $this->release->release($ticket, $technician, $context ?? new ActionContext, TicketEvent::Rejected, $reason, requireTechnician: true);
    }
}
