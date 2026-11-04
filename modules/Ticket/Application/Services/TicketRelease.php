<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * Takes a ticket back from its technician before work has started, returning it to the unassigned pool.
 * Shared by the technician refusing a job and the office withdrawing it.
 */
final class TicketRelease
{
    public function __construct(private readonly TicketWorkflow $workflow) {}

    public function release(Ticket $ticket, User $actor, ActionContext $context, TicketEvent $event, string $reason, bool $requireTechnician): Ticket
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi.']);
        }

        return DB::transaction(function () use ($ticket, $actor, $context, $event, $reason, $requireTechnician): Ticket {
            $current = $this->workflow->lock($ticket);

            if ($this->workflow->alreadyApplied($context)) {
                return $current;
            }

            $visit = $this->workflow->openVisit($current);

            if ($requireTechnician) {
                $this->workflow->assertTechnician($visit, $actor, $context);
            }

            $at = $this->workflow->occurredAt($current, $context);

            $this->workflow->cancelOpenVisit($current, $at, $reason);
            $current->forceFill(['technician_id' => null]);

            $this->workflow->transition($current, TicketStatus::Received, $event, $actor, $context, $at, $visit, $reason, ['technician_id' => $visit->technician_id]);

            return $current;
        });
    }
}
