<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

/**
 * What happened, as recorded in the status history.
 */
enum TicketEvent: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case Unassigned = 'unassigned';
    case Rejected = 'rejected';
    case Accepted = 'accepted';
    case Departed = 'departed';
    case Arrived = 'arrived';
    case HandlingStarted = 'handling_started';
    case Finished = 'finished';
    case Pended = 'pended';
    case ClosedNegative = 'closed_negative';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Tiket dibuat',
            self::Assigned => 'Ditugaskan',
            self::Reassigned => 'Dialihkan ke teknisi lain',
            self::Unassigned => 'Penugasan dicabut',
            self::Rejected => 'Ditolak teknisi',
            self::Accepted => 'Diterima teknisi',
            self::Departed => 'Berangkat',
            self::Arrived => 'Tiba di lokasi',
            self::HandlingStarted => 'Mulai dikerjakan',
            self::Finished => 'Selesai',
            self::Pended => 'Ditunda (pending)',
            self::ClosedNegative => 'Ditutup negatif',
            self::Rescheduled => 'Dijadwalkan ulang',
            self::Cancelled => 'Dibatalkan',
            self::Reopened => 'Dibuka kembali',
        };
    }
}
