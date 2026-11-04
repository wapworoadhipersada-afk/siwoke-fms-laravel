<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TicketWorkflow;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;

final class AcceptTicket
{
    public function __construct(private readonly TicketWorkflow $workflow) {}

    public function handle(Ticket $ticket, User $actor, ?ActionContext $context = null): Ticket
    {
        $context ??= new ActionContext;

        return DB::transaction(function () use ($ticket, $actor, $context): Ticket {
            $current = $this->workflow->lock($ticket);

            if ($this->workflow->alreadyApplied($context)) {
                return $current;
            }

            $visit = $this->workflow->openVisit($current);
            $this->workflow->assertTechnician($visit, $actor, $context);
            $at = $this->workflow->occurredAt($current, $context);

            $this->workflow->transition($current, TicketStatus::Accepted, TicketEvent::Accepted, $actor, $context, $at, $visit);
            $visit->forceFill(['accepted_at' => $at])->save();

            return $current;
        });
    }
}
