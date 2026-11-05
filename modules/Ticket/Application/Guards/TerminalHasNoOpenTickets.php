<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Guards;

use Illuminate\Validation\ValidationException;
use Modules\Master\Application\Contracts\TerminalDeletionGuard;
use Modules\Master\Domain\Models\Terminal;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * A TID with a ticket still being worked cannot be deleted.
 */
final class TerminalHasNoOpenTickets implements TerminalDeletionGuard
{
    public function assertCanDelete(Terminal $terminal): void
    {
        $closed = [TicketStatus::Finished, TicketStatus::DoneNegative, TicketStatus::Cancelled];
        $open = Ticket::query()->where('terminal_id', $terminal->id)->whereNotIn('status', array_map(static fn (TicketStatus $status): string => $status->value, $closed))->count();

        if ($open > 0) {
            throw ValidationException::withMessages(['terminal' => "TID {$terminal->tid} masih memiliki {$open} tiket yang belum selesai."]);
        }
    }
}
