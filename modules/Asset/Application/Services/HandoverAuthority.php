<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Services;

use App\Models\User;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\IAM\Domain\Permissions;

/**
 * Who may answer a handover. The side that receives the assets must be the one to confirm them;
 * that is the point of two-step custody, since nobody can accept on someone else's behalf.
 */
final class HandoverAuthority
{
    public function canResolve(User $actor, AssetHandover $handover): bool
    {
        return match ($handover->to_holder_type) {
            HolderType::User => $handover->to_holder_id === $actor->id && $actor->can(Permissions::ASSET_TRANSFER_RECEIVE),
            HolderType::Warehouse => $actor->can(Permissions::ASSET_TRANSFER_APPROVE)
                && Warehouse::query()->visibleTo($actor)->whereKey($handover->to_holder_id)->exists(),
            default => false,
        };
    }

    public function canCancel(User $actor, AssetHandover $handover): bool
    {
        return $handover->requested_by === $actor->id || $actor->can(Permissions::ASSET_TRANSFER_APPROVE);
    }
}
