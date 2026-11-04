<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Events;

/**
 * Raised after a ticket's status change has been committed. Berita Acara generation and the
 * outbound vendor update listen for this, so the workflow itself never calls them.
 */
final readonly class TicketStatusChanged
{
    public function __construct(
        public int $ticketId,
        public int $historyId,
    ) {}
}
