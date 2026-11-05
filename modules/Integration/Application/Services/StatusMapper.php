<?php

declare(strict_types=1);

namespace Modules\Integration\Application\Services;

use Modules\Integration\Domain\Models\StatusMapping;
use Modules\Ticket\Domain\Enums\TicketStatus;

/**
 * Translates our ticket status into the word a vendor expects. A vendor with no mapping for a status receives
 * our own status name, so nothing is ever silently dropped.
 */
final class StatusMapper
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $cache = [];

    public function forVendor(int $vendorId, TicketStatus $status): string
    {
        $this->cache[$vendorId] ??= StatusMapping::query()->where('vendor_id', $vendorId)->pluck('vendor_status', 'internal_status')->all();

        return $this->cache[$vendorId][$status->value] ?? $status->value;
    }
}
