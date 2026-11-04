<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Contracts;

use Illuminate\Validation\ValidationException;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * A rule that must hold before a visit may be closed (photos uploaded, assets recorded, ...).
 * Later modules register their own guards by tagging them `ticket.closure_guards`, so the ticket
 * module never needs to know about them.
 */
interface ClosureGuard
{
    /**
     * @throws ValidationException when the visit may not be closed with this outcome yet
     */
    public function assertCanClose(Ticket $ticket, TicketVisit $visit, VisitOutcome $outcome): void;
}
