<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Domain\Permissions;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Events\TicketStatusChanged;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketAssignment;
use Modules\Ticket\Domain\Models\TicketStatusHistory;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * The one place that changes a ticket's status. It enforces the state machine, writes the history,
 * and checks who may act, so no action can skip a rule by accident.
 */
final class TicketWorkflow
{
    /**
     * Re-reads the ticket under a row lock, so two people acting on it at once queue up.
     */
    public function lock(Ticket $ticket): Ticket
    {
        return Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * A phone that lost its connection resends the same action. If we already recorded it, do nothing.
     */
    public function alreadyApplied(ActionContext $context): bool
    {
        return $context->clientUuid !== null
            && TicketStatusHistory::query()->where('client_uuid', $context->clientUuid)->exists();
    }

    /**
     * When the action really happened. The phone's clock is accepted only inside sane bounds, and an
     * action cannot be dated before the previous one, so history stays in order.
     */
    public function occurredAt(Ticket $ticket, ActionContext $context): CarbonInterface
    {
        $at = $context->occurredAt ?? now();

        if ($at->greaterThan(now()->addMinutes((int) config('tickets.max_future_minutes')))) {
            throw ValidationException::withMessages(['occurred_at' => 'Waktu kejadian tidak boleh di masa depan. Periksa jam perangkat.']);
        }

        if ($at->lessThan(now()->subDays((int) config('tickets.max_past_days')))) {
            throw ValidationException::withMessages(['occurred_at' => 'Waktu kejadian terlalu lama. Hubungi operasional.']);
        }

        $last = TicketStatusHistory::query()->where('ticket_id', $ticket->id)->orderByDesc('occurred_at')->first();

        if ($last instanceof TicketStatusHistory && $at->lessThan($last->occurred_at)) {
            throw ValidationException::withMessages(['occurred_at' => 'Waktu kejadian lebih awal dari kejadian sebelumnya pada tiket ini.']);
        }

        return $at;
    }

    public function openVisit(Ticket $ticket): TicketVisit
    {
        $visit = TicketVisit::query()->where('ticket_id', $ticket->id)->whereNull('outcome')->first();

        if (! $visit instanceof TicketVisit) {
            throw ValidationException::withMessages(['ticket' => 'Tiket ini tidak memiliki kunjungan yang sedang berjalan.']);
        }

        return $visit;
    }

    /**
     * Only the technician the visit belongs to may act on it, unless an office user with the override
     * permission explicitly acts on their behalf.
     */
    public function assertTechnician(TicketVisit $visit, User $actor, ActionContext $context): void
    {
        if ($visit->technician_id === $actor->id) {
            return;
        }

        if ($context->onBehalf && $actor->can(Permissions::TICKET_CLOSE_OVERRIDE)) {
            return;
        }

        throw new AuthorizationException('Tiket ini bukan tugas Anda.');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function transition(
        Ticket $ticket,
        TicketStatus $to,
        TicketEvent $event,
        ?User $actor,
        ActionContext $context,
        CarbonInterface $at,
        ?TicketVisit $visit = null,
        ?string $reason = null,
        array $meta = [],
    ): TicketStatusHistory {
        $from = $ticket->status;

        if (! $from->canMoveTo($to)) {
            throw ValidationException::withMessages(['status' => "Tiket berstatus {$from->label()}; tidak dapat {$this->lower($event->label())}."]);
        }

        $ticket->forceFill(['status' => $to, 'version' => $ticket->version + 1])->save();

        return $this->record($ticket, $from, $to, $event, $actor, $context, $at, $visit, $reason, $meta);
    }

    /**
     * Writes a history row without moving the status (used when a ticket is first created).
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(
        Ticket $ticket,
        ?TicketStatus $from,
        TicketStatus $to,
        TicketEvent $event,
        ?User $actor,
        ActionContext $context,
        CarbonInterface $at,
        ?TicketVisit $visit = null,
        ?string $reason = null,
        array $meta = [],
    ): TicketStatusHistory {
        $history = TicketStatusHistory::query()->create([
            'ticket_id' => $ticket->id,
            'visit_id' => $visit?->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->id,
            'actor_type' => $actor instanceof User ? 'user' : 'system',
            'reason' => $reason,
            'meta' => $context->onBehalf ? [...$meta, 'on_behalf' => true] : $meta,
            'ip' => request()->ip(),
            'device_id' => $context->deviceId,
            'client_uuid' => $context->clientUuid,
            'occurred_at' => $at,
        ]);

        DB::afterCommit(static fn () => event(new TicketStatusChanged($ticket->id, $history->id)));

        return $history;
    }

    /**
     * Ends an open visit that will not be finished (reassigned away, rejected, or the ticket cancelled).
     */
    public function cancelOpenVisit(Ticket $ticket, CarbonInterface $at, string $reason): void
    {
        $visit = TicketVisit::query()->where('ticket_id', $ticket->id)->whereNull('outcome')->first();

        if (! $visit instanceof TicketVisit) {
            return;
        }

        $visit->forceFill(['outcome' => 'cancelled', 'closed_at' => $at, 'notes' => $reason])->save();
        $this->closeAssignment($visit, $at, $reason);
    }

    public function closeAssignment(TicketVisit $visit, CarbonInterface $at, string $reason): void
    {
        TicketAssignment::query()
            ->where('visit_id', $visit->id)
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => $at, 'unassign_reason' => $reason]);
    }

    private function lower(string $text): string
    {
        return mb_strtolower($text);
    }
}
