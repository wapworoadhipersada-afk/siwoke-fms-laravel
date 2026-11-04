<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\HandoverAuthority;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetHandoverItem;
use Modules\Asset\Domain\Models\AssetMovement;

/**
 * Step two: the receiving side confirms. This is the moment custody actually changes, so it is
 * the moment the ledger entry is written.
 */
final class AcceptHandover
{
    public function __construct(
        private readonly MoveAssets $move,
        private readonly HandoverAuthority $authority,
    ) {}

    public function handle(
        AssetHandover $handover,
        User $actor,
        ?string $note = null,
        string|float|null $latitude = null,
        string|float|null $longitude = null,
        ?int $deviceId = null,
    ): AssetMovement {
        return DB::transaction(function () use ($handover, $actor, $note, $latitude, $longitude, $deviceId): AssetMovement {
            // Re-read under lock: two people answering at once must not both succeed.
            $current = AssetHandover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== HandoverStatus::Pending) {
                throw ValidationException::withMessages(['handover' => 'Serah-terima ini sudah diselesaikan.']);
            }

            if (! $this->authority->canResolve($actor, $current)) {
                throw new AuthorizationException('Anda bukan penerima serah-terima ini.');
            }

            $current->load('items');

            $movement = $this->move->handle(new MovementCommand(
                type: $current->type,
                items: array_values($current->items->map(static fn (AssetHandoverItem $item): MoveItem => new MoveItem(
                    $item->asset_id,
                    $item->asset_model_id,
                    $item->owner_vendor_id,
                    $item->quantity,
                ))->all()),
                from: $current->from(),
                to: $current->to(),
                actor: $actor,
                notes: $note ?? $current->note,
                latitude: $latitude,
                longitude: $longitude,
                deviceId: $deviceId,
            ), $current);

            $current->forceFill([
                'status' => HandoverStatus::Accepted,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
                'movement_id' => $movement->id,
            ])->save();

            return $movement;
        });
    }
}
