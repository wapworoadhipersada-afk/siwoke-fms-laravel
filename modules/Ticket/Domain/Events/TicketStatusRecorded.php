<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Events;

/**
 * Raised in the same database transaction that records a status change, before it is committed. Use it for
 * things that must succeed or fail together with the change itself (writing an outbox row for a vendor); use
 * TicketStatusChanged, raised after commit, for everything that can happen later.
 */
final readonly class TicketStatusRecorded
{
    public function __construct(
        public int $ticketId,
        public int $historyId,
    ) {}
}
