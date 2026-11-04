<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

/**
 * The ticket state machine. This enum is the single definition of which status may follow which;
 * the workflow engine refuses anything not listed here.
 *
 *   received ─assign→ assigned ─accept→ accepted ─depart→ on_the_way ─arrive→ arrived ─start→ handling
 *      ▲                 │ reject            │ arrive ───────────────────────▲                  │
 *      └─────────────────┴───────────────────┘                                                  ├→ finished
 *                                                                                               ├→ pending ─reschedule→ assigned
 *                                                                                               └→ done_negative
 *   finished / done_negative ─reopen→ received        (any open status before arrival ─cancel→ cancelled)
 */
enum TicketStatus: string
{
    case Received = 'received';
    case Assigned = 'assigned';
    case Accepted = 'accepted';
    case OnTheWay = 'on_the_way';
    case Arrived = 'arrived';
    case Handling = 'handling';
    case Pending = 'pending';
    case Finished = 'finished';
    case DoneNegative = 'done_negative';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Received => [self::Assigned, self::Cancelled],
            // Assigned to Assigned is a reassignment to a different technician.
            self::Assigned => [self::Assigned, self::Accepted, self::Received, self::Cancelled],
            self::Accepted => [self::Assigned, self::OnTheWay, self::Arrived, self::Received, self::Cancelled],
            self::OnTheWay => [self::Arrived, self::Cancelled],
            // A technician who finds the merchant closed can end the visit right after arriving; only a finish needs work done.
            self::Arrived => [self::Handling, self::Pending, self::DoneNegative],
            self::Handling => [self::Finished, self::Pending, self::DoneNegative],
            self::Pending => [self::Assigned, self::Cancelled],
            self::Finished, self::DoneNegative => [self::Received],
            self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * Nothing more happens to the ticket unless someone reopens it (or, for cancelled, never).
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Finished, self::DoneNegative, self::Cancelled], true);
    }

    /**
     * A technician is currently responsible for it.
     */
    public function hasTechnician(): bool
    {
        return in_array($this, [self::Assigned, self::Accepted, self::OnTheWay, self::Arrived, self::Handling], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Diterima',
            self::Assigned => 'Ditugaskan',
            self::Accepted => 'Diterima teknisi',
            self::OnTheWay => 'Dalam perjalanan',
            self::Arrived => 'Tiba di lokasi',
            self::Handling => 'Dikerjakan',
            self::Pending => 'Pending',
            self::Finished => 'Selesai',
            self::DoneNegative => 'Done negative',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
