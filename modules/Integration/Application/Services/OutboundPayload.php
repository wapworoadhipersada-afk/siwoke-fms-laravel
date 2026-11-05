<?php

declare(strict_types=1);

namespace Modules\Integration\Application\Services;

use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketStatusHistory;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * What we tell a vendor when one of their tickets changes. Deliberately small and free of personal data:
 * no technician names or phone numbers, only what the vendor needs to update its own record.
 */
final class OutboundPayload
{
    public function __construct(private readonly StatusMapper $status) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Ticket $ticket, TicketStatusHistory $history): array
    {
        $ticket->loadMissing(['terminal', 'type']);
        $visit = $history->visit_id === null ? null : TicketVisit::query()->with('reasonCode')->find($history->visit_id);

        return [
            'event' => 'ticket.status_changed',
            'occurred_at' => $history->occurred_at->toIso8601String(),
            'ticket' => [
                'vendor_ticket_no' => $ticket->vendor_ticket_no,
                'ticket_no' => $ticket->ticket_no,
                'type' => $ticket->type->code,
                'tid' => $ticket->terminal->tid,
                'status' => $this->status->forVendor($ticket->vendor_id, $history->to_status),
                'internal_status' => $history->to_status->value,
                'change' => $history->event->value,
            ],
            'visit' => $visit instanceof TicketVisit && $visit->outcome !== null ? [
                'visit_no' => $visit->visit_no,
                'result' => $visit->outcome->value,
                'reason' => $visit->reasonCode?->name,
                'notes' => $visit->notes,
                'closed_at' => $visit->closed_at?->toIso8601String(),
            ] : null,
        ];
    }
}
