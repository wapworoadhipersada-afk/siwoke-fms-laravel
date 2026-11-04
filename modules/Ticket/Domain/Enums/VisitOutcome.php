<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

/**
 * How one visit ended. An open visit has no outcome yet.
 */
enum VisitOutcome: string
{
    case Finish = 'finish';
    case Pending = 'pending';
    case DoneNegative = 'done_negative';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Finish => 'Selesai',
            self::Pending => 'Pending',
            self::DoneNegative => 'Done negative',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
