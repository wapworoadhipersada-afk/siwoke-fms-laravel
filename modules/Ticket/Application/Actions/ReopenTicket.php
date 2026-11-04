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
 * Brings a closed ticket back to the unassigned pool (the vendor disputes the result, or it was closed wrongly).
 * Never silent: a reason is mandatory and the history keeps the original closure.
 */
final class ReopenTicket
{
    public function __construct(private readonly TicketWorkflow $workflow) {}

    public function handle(Ticket $ticket, User $actor, string $reason): Ticket
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan membuka kembali wajib diisi.']);
        }

        return DB::transaction(function () use ($ticket, $actor, $reason): Ticket {
            $current = $this->workflow->lock($ticket);

            $current->forceFill(['closed_at' => null, 'final_result' => null, 'technician_id' => null]);

            $this->workflow->transition($current, TicketStatus::Received, TicketEvent::Reopened, $actor, new ActionContext, now(), null, $reason, [
                'previous_result' => $current->status->value,
            ]);

            return $current;
        });
    }
}
