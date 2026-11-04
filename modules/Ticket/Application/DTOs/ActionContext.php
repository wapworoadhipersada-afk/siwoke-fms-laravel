<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\DTOs;

use Carbon\CarbonInterface;

/**
 * The circumstances of one action on a ticket, mostly relevant for the mobile app:
 * when it really happened, where, from which device, and an id that makes a retry harmless.
 */
final readonly class ActionContext
{
    public function __construct(
        public ?CarbonInterface $occurredAt = null,
        public string|float|null $latitude = null,
        public string|float|null $longitude = null,
        public ?float $accuracy = null,
        public ?int $deviceId = null,
        public ?string $clientUuid = null,
        // An office user with the override permission acting in place of the technician.
        public bool $onBehalf = false,
    ) {}

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
