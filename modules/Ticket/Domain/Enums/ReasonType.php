<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

enum ReasonType: string
{
    case Pending = 'pending';
    case Negative = 'negative';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Alasan pending',
            self::Negative => 'Alasan done negative',
        };
    }
}
