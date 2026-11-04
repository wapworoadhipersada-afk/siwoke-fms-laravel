<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

enum TicketSource: string
{
    case Manual = 'manual';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Input manual',
            self::Api => 'API vendor',
        };
    }
}
