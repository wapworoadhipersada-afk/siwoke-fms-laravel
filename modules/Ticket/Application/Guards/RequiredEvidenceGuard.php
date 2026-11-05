<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Guards;

use Illuminate\Validation\ValidationException;
use Modules\Ticket\Application\Contracts\ClosureGuard;
use Modules\Ticket\Application\Services\EvidenceRules;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * A visit cannot be closed until the photos and signatures its ticket type asks for are attached.
 */
final class RequiredEvidenceGuard implements ClosureGuard
{
    public function __construct(private readonly EvidenceRules $rules) {}

    public function assertCanClose(Ticket $ticket, TicketVisit $visit, VisitOutcome $outcome): void
    {
        $missing = $this->rules->missing($ticket, $visit, $outcome);

        if ($missing !== []) {
            throw ValidationException::withMessages(['attachments' => 'Bukti belum lengkap. '.implode(' ', $missing)]);
        }
    }
}
