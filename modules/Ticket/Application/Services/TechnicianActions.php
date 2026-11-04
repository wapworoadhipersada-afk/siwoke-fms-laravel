<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Services;

use Modules\Ticket\Domain\Enums\TicketStatus;

/**
 * What a technician may do next at each status. The mobile app shows buttons from this list, so it
 * never has to know the state machine; the server still enforces it on every call.
 */
final class TechnicianActions
{
    /**
     * @return list<string>
     */
    public static function for(TicketStatus $status): array
    {
        return match ($status) {
            TicketStatus::Assigned => ['accept', 'reject'],
            TicketStatus::Accepted => ['depart', 'arrive', 'reject'],
            TicketStatus::OnTheWay => ['arrive'],
            TicketStatus::Arrived => ['start', 'close_pending', 'close_negative'],
            TicketStatus::Handling => ['close_finish', 'close_pending', 'close_negative'],
            default => [],
        };
    }
}
