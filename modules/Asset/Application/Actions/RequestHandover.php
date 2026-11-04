<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\MovementRules;
use Modules\Asset\Application\Services\StockBook;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetHandoverItem;
use Modules\Asset\Domain\Models\AssetModel;

/**
 * Step one of a custody change: the sender offers assets to a technician or warehouse.
 * Nothing moves yet. Serialized assets are marked "in transit" so they cannot be offered twice,
 * and consumables are reserved against the sender's balance.
 */
final class RequestHandover
{
    public function __construct(
        private readonly MovementRules $rules,
        private readonly StockBook $stock,
    ) {}

    public function handle(MovementCommand $command): AssetHandover
    {
        if (! $command->type->requiresHandover()) {
            throw ValidationException::withMessages(['type' => "{$command->type->label()} tidak memerlukan serah-terima."]);
        }

        if ($command->items === []) {
            throw ValidationException::withMessages(['items' => 'Pilih minimal satu aset.']);
        }

        return DB::transaction(function () use ($command): AssetHandover {
            $this->rules->assertHolders($command->type, $command->from, $command->to);
            $this->rules->assertEndpoints($command);

            $assetIds = [];

            foreach ($command->items as $item) {
                if ($item->assetId === null) {
                    continue;
                }

                if (in_array($item->assetId, $assetIds, true)) {
                    throw ValidationException::withMessages(['items' => 'Aset yang sama dipilih lebih dari sekali.']);
                }

                $assetIds[] = $item->assetId;
            }

            $assets = Asset::query()->whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()->get()->load('model.category')->keyBy('id');

            /** @var array<string, int> $requested quantity asked per consumable, to check against the balance once */
            $requested = [];

            foreach ($command->items as $item) {
                if ($item->isSerialized()) {
                    $asset = $assets->get($item->assetId);

                    if (! $asset instanceof Asset) {
                        throw ValidationException::withMessages(['items' => 'Aset tidak ditemukan.']);
                    }

                    $this->rules->assertSerialItem($asset, $command);

                    continue;
                }

                $model = AssetModel::query()->with('category')->find($item->assetModelId);

                if (! $model instanceof AssetModel) {
                    throw ValidationException::withMessages(['items' => 'Jenis barang tidak ditemukan.']);
                }

                $this->rules->assertQuantityItem($item, $model, $command->type);

                $key = "{$item->assetModelId}:{$item->ownerVendorId}";
                $requested[$key] = ($requested[$key] ?? 0) + $item->quantity;

                $available = $this->stock->available($item->assetModelId, $item->ownerVendorId, $command->from);

                if ($requested[$key] > $available) {
                    throw ValidationException::withMessages([
                        'quantity' => "Stok {$model->displayName()} tidak cukup: tersedia {$available}, diminta {$requested[$key]}.",
                    ]);
                }
            }

            $handover = AssetHandover::query()->create([
                'type' => $command->type,
                'status' => HandoverStatus::Pending,
                'from_holder_type' => $command->from->type,
                'from_holder_id' => $command->from->id,
                'to_holder_type' => $command->to->type,
                'to_holder_id' => $command->to->id,
                'requested_by' => $command->actor->id,
                'requested_at' => now(),
                'note' => $command->notes,
            ]);

            foreach ($command->items as $item) {
                $asset = $item->isSerialized() ? $assets->get($item->assetId) : null;

                AssetHandoverItem::query()->create([
                    'handover_id' => $handover->id,
                    'asset_id' => $item->assetId,
                    'asset_model_id' => $item->assetModelId,
                    'owner_vendor_id' => $item->ownerVendorId,
                    'quantity' => $item->quantity,
                    'from_status' => $asset?->status,
                ]);

                $asset?->forceFill([
                    'status' => 'in_transit',
                    'pending_handover_id' => $handover->id,
                    'version' => $asset->version + 1,
                ])->save();
            }

            return $handover->load('items');
        });
    }
}
