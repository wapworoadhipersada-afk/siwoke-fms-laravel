<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TicketRelease;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * The office withdraws a job from its technician before work has started.
 */
final class UnassignTicket
{
    public function __construct(private readonly TicketRelease $release) {}

    public function handle(Ticket $ticket, User $actor, string $reason): Ticket
    {
        return $this->release->release($ticket, $actor, new ActionContext, TicketEvent::Unassigned, $reason, requireTechnician: false);
    }
}
