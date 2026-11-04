<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Domain\Models\AssetMovement;
use Modules\Asset\Domain\Models\AssetMovementItem;

/**
 * The only place that writes to the movement ledger. It records a fact; it decides nothing.
 */
final class LedgerWriter
{
    public function record(MovementCommand $command, ?int $handoverId = null): AssetMovement
    {
        $movement = AssetMovement::query()->create([
            'movement_no' => $this->nextNumber(),
            'type' => $command->type,
            'from_holder_type' => $command->from->type,
            'from_holder_id' => $command->from->id,
            'to_holder_type' => $command->to->type,
            'to_holder_id' => $command->to->id,
            'actor_id' => $command->actor->id,
            'handover_id' => $handoverId,
            'reference_type' => $command->referenceType,
            'reference_id' => $command->referenceId,
            'notes' => $command->notes,
            'occurred_at' => $command->occurredAt ?? now(),
            'latitude' => $command->latitude,
            'longitude' => $command->longitude,
            'device_id' => $command->deviceId,
            'meta' => ['ip' => request()->ip(), 'user_agent' => request()->userAgent()],
        ]);

        foreach ($command->items as $item) {
            AssetMovementItem::query()->create([
                'movement_id' => $movement->id,
                'asset_id' => $item->assetId,
                'asset_model_id' => $item->assetModelId,
                'owner_vendor_id' => $item->ownerVendorId,
                'quantity' => $item->quantity,
            ]);
        }

        return $movement;
    }

    /**
     * Gap-free ordering is not needed, only uniqueness; a database sequence gives that without a race.
     */
    private function nextNumber(): string
    {
        $row = DB::selectOne("select nextval('asset_movement_number_seq') as number");

        return sprintf('MV-%s-%06d', now()->format('Ymd'), (int) ($row->number ?? 0));
    }
}
