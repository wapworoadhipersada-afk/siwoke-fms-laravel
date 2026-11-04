<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\LedgerWriter;
use Modules\Asset\Application\Services\MovementRules;
use Modules\Asset\Application\Services\StockBook;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetHandoverItem;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetMovement;

/**
 * The movement engine. In one transaction it checks every rule, writes the ledger entry, and moves
 * the snapshot (asset holder and status, or stock balances) so the two can never disagree.
 *
 * Movements that change custody between people or places go through a handover instead; this runs
 * them only once the receiving side has confirmed (`$fulfilling`).
 */
final class MoveAssets
{
    public function __construct(
        private readonly MovementRules $rules,
        private readonly LedgerWriter $ledger,
        private readonly StockBook $stock,
    ) {}

    public function handle(MovementCommand $command, ?AssetHandover $fulfilling = null): AssetMovement
    {
        $type = $command->type;

        if ($command->items === []) {
            throw ValidationException::withMessages(['items' => 'Pilih minimal satu aset.']);
        }

        if ($type === MovementType::Receive || $type === MovementType::Adjustment) {
            throw ValidationException::withMessages(['type' => "{$type->label()} tidak diproses di sini."]);
        }

        if ($type->requiresHandover() && ! $fulfilling instanceof AssetHandover) {
            throw ValidationException::withMessages(['type' => "{$type->label()} memerlukan serah-terima yang dikonfirmasi penerima."]);
        }

        return DB::transaction(function () use ($command, $fulfilling): AssetMovement {
            $this->rules->assertHolders($command->type, $command->from, $command->to);
            $this->rules->assertEndpoints($command);

            $assets = $this->lockAssets($command);
            $fulfilling?->loadMissing('items');

            foreach ($command->items as $item) {
                $this->validateItem($command, $item, $assets, $fulfilling);
            }

            $movement = $this->ledger->record($command, $fulfilling?->id);

            foreach ($command->items as $item) {
                $item->isSerialized()
                    ? $this->placeAsset($assets[$item->assetId], $command, $movement)
                    : $this->moveStock($item, $command);
            }

            return $movement;
        });
    }

    /**
     * Locks every asset involved, in id order, so two movements touching the same assets queue instead of deadlocking.
     *
     * @return Collection<int, Asset>
     */
    private function lockAssets(MovementCommand $command): Collection
    {
        $ids = [];

        foreach ($command->items as $item) {
            if ($item->assetId === null) {
                continue;
            }

            if (in_array($item->assetId, $ids, true)) {
                throw ValidationException::withMessages(['items' => 'Aset yang sama dipilih lebih dari sekali.']);
            }

            $ids[] = $item->assetId;
        }

        $assets = Asset::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $assets->load('model.category');

        return $assets->keyBy('id');
    }

    /**
     * @param  Collection<int, Asset>  $assets
     */
    private function validateItem(MovementCommand $command, MoveItem $item, Collection $assets, ?AssetHandover $fulfilling): void
    {
        if ($item->isSerialized()) {
            $asset = $assets->get($item->assetId);

            if (! $asset instanceof Asset) {
                throw ValidationException::withMessages(['items' => 'Aset tidak ditemukan.']);
            }

            $this->rules->assertSerialItem($asset, $command, $fulfilling);

            if ($fulfilling instanceof AssetHandover) {
                // The status the asset had when it entered the handover is what must satisfy this movement.
                $entry = $fulfilling->items->first(static fn (AssetHandoverItem $line): bool => $line->asset_id === $asset->id);
                $this->rules->assertHandoverStatus($entry instanceof AssetHandoverItem ? ($entry->from_status ?? $asset->status) : $asset->status, $asset, $command->type);
            }

            return;
        }

        $model = AssetModel::query()->with('category')->find($item->assetModelId);

        if (! $model instanceof AssetModel) {
            throw ValidationException::withMessages(['items' => 'Jenis barang tidak ditemukan.']);
        }

        $this->rules->assertQuantityItem($item, $model, $command->type);
    }

    private function placeAsset(Asset $asset, MovementCommand $command, AssetMovement $movement): void
    {
        $status = $command->type->resultStatus()
            ?? throw ValidationException::withMessages(['type' => 'Jenis perpindahan ini tidak mengubah status aset.']);

        $asset->forceFill([
            'status' => $status,
            'holder_type' => $command->to->type,
            'holder_id' => $command->to->id,
            'pending_handover_id' => null,
            'last_movement_id' => $movement->id,
            'version' => $asset->version + 1,
        ])->save();
    }

    private function moveStock(MoveItem $item, MovementCommand $command): void
    {
        $this->stock->change($item->assetModelId, $item->ownerVendorId, $command->from, -$item->quantity);
        $this->stock->change($item->assetModelId, $item->ownerVendorId, $command->to, $item->quantity);
    }
}
