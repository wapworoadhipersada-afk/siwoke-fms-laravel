<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ticket\Application\Contracts\ClosureGuard;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TicketWorkflow;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\ReasonCode;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * The technician ends the visit with one of three results.
 *
 *  - finish:        the work is done (only after handling started)
 *  - pending:       not done, someone will come back; needs a pending reason
 *  - done_negative: cannot be done and nobody will come back; needs a negative reason and notes
 */
final class CloseVisit
{
    public function __construct(
        private readonly TicketWorkflow $workflow,
        private readonly Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the checklist answers for the ticket type
     */
    public function handle(
        Ticket $ticket,
        User $actor,
        VisitOutcome $outcome,
        ?ActionContext $context = null,
        ?ReasonCode $reason = null,
        ?string $notes = null,
        array $data = [],
    ): Ticket {
        $context ??= new ActionContext;
        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        [$status, $event] = match ($outcome) {
            VisitOutcome::Finish => [TicketStatus::Finished, TicketEvent::Finished],
            VisitOutcome::Pending => [TicketStatus::Pending, TicketEvent::Pended],
            VisitOutcome::DoneNegative => [TicketStatus::DoneNegative, TicketEvent::ClosedNegative],
            VisitOutcome::Cancelled => throw ValidationException::withMessages(['result' => 'Hasil kunjungan tidak valid.']),
        };

        $this->assertReason($outcome, $reason, $notes);

        return DB::transaction(function () use ($ticket, $actor, $outcome, $context, $reason, $notes, $data, $status, $event): Ticket {
            $current = $this->workflow->lock($ticket);

            if ($this->workflow->alreadyApplied($context)) {
                return $current;
            }

            $visit = $this->workflow->openVisit($current);
            $this->workflow->assertTechnician($visit, $actor, $context);
            $at = $this->workflow->occurredAt($current, $context);

            // Fails early with the state machine's own message when the status does not allow this result.
            if (! $current->status->canMoveTo($status)) {
                throw ValidationException::withMessages(['status' => "Tiket berstatus {$current->status->label()}; tidak dapat ditutup dengan hasil {$outcome->label()}."]);
            }

            $this->runGuards($current, $visit, $outcome);

            $visit->forceFill([
                'outcome' => $outcome,
                'closed_at' => $at,
                'reason_code_id' => $reason?->id,
                'notes' => $notes,
                'close_latitude' => $context->latitude,
                'close_longitude' => $context->longitude,
                'result_data' => $data,
            ])->save();

            $closed = $outcome !== VisitOutcome::Pending;

            // The ticket no longer belongs to anyone; the visit remembers who did the work.
            $current->forceFill([
                'technician_id' => null,
                'closed_at' => $closed ? $at : null,
                'final_result' => $closed ? $outcome->value : null,
            ]);

            $this->workflow->closeAssignment($visit, $at, 'Kunjungan selesai');

            $this->workflow->transition($current, $status, $event, $actor, $context, $at, $visit, $reason?->name, array_filter([
                'reason_code' => $reason?->code,
                'notes' => $notes,
            ]));

            return $current;
        });
    }

    private function assertReason(VisitOutcome $outcome, ?ReasonCode $reason, ?string $notes): void
    {
        $required = match ($outcome) {
            VisitOutcome::Pending => ReasonType::Pending,
            VisitOutcome::DoneNegative => ReasonType::Negative,
            default => null,
        };

        if ($required === null) {
            return;
        }

        if (! $reason instanceof ReasonCode || ! $reason->is_active || $reason->type !== $required) {
            throw ValidationException::withMessages(['reason_code' => "Pilih {$this->lower($required->label())} yang sesuai."]);
        }

        if ($outcome === VisitOutcome::DoneNegative && $notes === null) {
            throw ValidationException::withMessages(['notes' => 'Catatan wajib diisi untuk done negative.']);
        }
    }

    private function runGuards(Ticket $ticket, TicketVisit $visit, VisitOutcome $outcome): void
    {
        foreach ($this->container->tagged('ticket.closure_guards') as $guard) {
            if ($guard instanceof ClosureGuard) {
                $guard->assertCanClose($ticket, $visit, $outcome);
            }
        }
    }

    private function lower(string $text): string
    {
        return mb_strtolower($text);
    }
}
