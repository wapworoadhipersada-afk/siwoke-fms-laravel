<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Domain\Permissions;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TechnicianCoverage;
use Modules\Ticket\Application\Services\TicketWorkflow;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketAssignment;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * Hands a ticket to a technician. This one action covers the first assignment, giving a pending
 * ticket another visit (rescheduling), and moving a not-yet-started ticket to a different technician.
 */
final class AssignTicket
{
    public function __construct(
        private readonly TicketWorkflow $workflow,
        private readonly TechnicianCoverage $coverage,
    ) {}

    public function handle(Ticket $ticket, User $technician, User $assigner, ?CarbonInterface $scheduledAt = null, ?string $reason = null): Ticket
    {
        if (! $technician->is_active || ! $technician->can(Permissions::TICKET_WORK)) {
            throw ValidationException::withMessages(['technician' => 'Teknisi tidak aktif atau tidak berhak mengerjakan tiket.']);
        }

        $why = $this->coverage->reasonNot($technician, $ticket);

        if ($why !== null) {
            throw ValidationException::withMessages(['technician' => $why]);
        }

        return DB::transaction(function () use ($ticket, $technician, $assigner, $scheduledAt, $reason): Ticket {
            $current = $this->workflow->lock($ticket);
            $from = $current->status;
            $context = new ActionContext;
            $at = now();

            if (! in_array($from, [TicketStatus::Received, TicketStatus::Pending, TicketStatus::Assigned, TicketStatus::Accepted], true)) {
                throw ValidationException::withMessages(['status' => "Tiket berstatus {$from->label()}; tidak dapat ditugaskan."]);
            }

            $visit = TicketVisit::query()->where('ticket_id', $current->id)->whereNull('outcome')->first();

            if ($visit instanceof TicketVisit) {
                // Not started yet, so the job can simply move to someone else.
                if ($visit->technician_id === $technician->id) {
                    throw ValidationException::withMessages(['technician' => 'Tiket sudah ditugaskan ke teknisi ini.']);
                }

                $this->workflow->closeAssignment($visit, $at, $reason ?? 'Dialihkan ke teknisi lain');
                $visit->forceFill([
                    'technician_id' => $technician->id,
                    'accepted_at' => null,
                    'scheduled_at' => $scheduledAt ?? $visit->scheduled_at,
                ])->save();

                $event = TicketEvent::Reassigned;
            } else {
                $visit = new TicketVisit([
                    'ticket_id' => $current->id,
                    'visit_no' => (int) TicketVisit::query()->where('ticket_id', $current->id)->max('visit_no') + 1,
                    'technician_id' => $technician->id,
                    'scheduled_at' => $scheduledAt ?? $current->scheduled_at,
                ]);
                $visit->save();

                $event = $from === TicketStatus::Pending ? TicketEvent::Rescheduled : TicketEvent::Assigned;
            }

            TicketAssignment::query()->create([
                'ticket_id' => $current->id,
                'visit_id' => $visit->id,
                'technician_id' => $technician->id,
                'assigned_by' => $assigner->id,
                'assigned_at' => $at,
            ]);

            $current->forceFill(['technician_id' => $technician->id]);

            $this->workflow->transition($current, TicketStatus::Assigned, $event, $assigner, $context, $at, $visit, $reason, [
                'technician_id' => $technician->id,
                'technician' => $technician->name,
                'visit_no' => $visit->visit_no,
            ]);

            return $current;
        });
    }
}
