<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\ValueObjects;

use App\Models\User;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Master\Domain\Models\Terminal;

/**
 * Who or what currently has custody of an asset: a warehouse, a technician, a terminal at a merchant, or nobody inside the company.
 */
final readonly class Holder
{
    public function __construct(
        public HolderType $type,
        public ?int $id = null,
    ) {}

    public static function warehouse(Warehouse|int $warehouse): self
    {
        return new self(HolderType::Warehouse, $warehouse instanceof Warehouse ? $warehouse->id : $warehouse);
    }

    public static function user(User|int $user): self
    {
        return new self(HolderType::User, $user instanceof User ? $user->id : $user);
    }

    public static function terminal(Terminal|int $terminal): self
    {
        return new self(HolderType::Terminal, $terminal instanceof Terminal ? $terminal->id : $terminal);
    }

    public static function external(): self
    {
        return new self(HolderType::External);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
