<?php

declare(strict_types=1);

namespace Modules\Asset\Application\DTOs;

use App\Models\User;
use Carbon\CarbonInterface;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\ValueObjects\Holder;

/**
 * A request to change custody. Callers (web, mobile, and later the ticket flow) build one of these
 * and hand it to the movement engine, which owns every rule about whether it is allowed.
 */
final readonly class MovementCommand
{
    /**
     * @param  list<MoveItem>  $items
     */
    public function __construct(
        public MovementType $type,
        public array $items,
        public Holder $from,
        public Holder $to,
        public User $actor,
        public ?string $notes = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public ?CarbonInterface $occurredAt = null,
        public string|float|null $latitude = null,
        public string|float|null $longitude = null,
        public ?int $deviceId = null,
    ) {}
}
