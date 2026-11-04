<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetStock;
use Modules\Asset\Domain\ValueObjects\Holder;

/**
 * Keeps the balances of consumables. A balance can never go below zero.
 */
final class StockBook
{
    /**
     * Adds (or removes, when negative) stock for a holder. Holders that do not keep stock are ignored,
     * so callers can pass both ends of a movement without checking.
     */
    public function change(int $assetModelId, int $vendorId, Holder $holder, int $delta): void
    {
        if (! $holder->type->holdsStock() || $holder->id === null) {
            return;
        }

        $key = [
            'asset_model_id' => $assetModelId,
            'owner_vendor_id' => $vendorId,
            'holder_type' => $holder->type->value,
            'holder_id' => $holder->id,
        ];

        // Make sure the row exists, then lock it, so two concurrent movements queue up instead of both reading the same balance.
        DB::table('asset_stocks')->insertOrIgnore([...$key, 'quantity' => 0, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $stock = AssetStock::query()->where($key)->lockForUpdate()->firstOrFail();
        $balance = $stock->quantity + $delta;

        if ($balance < 0) {
            $name = AssetModel::query()->find($assetModelId)?->displayName() ?? 'barang';

            throw ValidationException::withMessages([
                'quantity' => "Stok {$name} tidak cukup: tersedia {$stock->quantity}, diminta ".abs($delta).'.',
            ]);
        }

        $stock->forceFill(['quantity' => $balance, 'version' => $stock->version + 1])->save();
    }

    /**
     * What a holder can still hand out: the balance minus what pending handovers have already promised.
     */
    public function available(int $assetModelId, int $vendorId, Holder $holder): int
    {
        if ($holder->id === null) {
            return 0;
        }

        $balance = (int) AssetStock::query()
            ->where('asset_model_id', $assetModelId)
            ->where('owner_vendor_id', $vendorId)
            ->where('holder_type', $holder->type->value)
            ->where('holder_id', $holder->id)
            ->value('quantity');

        $reserved = (int) DB::table('asset_handover_items as item')
            ->join('asset_handovers as handover', 'handover.id', '=', 'item.handover_id')
            ->where('handover.status', HandoverStatus::Pending->value)
            ->where('handover.from_holder_type', $holder->type->value)
            ->where('handover.from_holder_id', $holder->id)
            ->whereNull('item.asset_id')
            ->where('item.asset_model_id', $assetModelId)
            ->where('item.owner_vendor_id', $vendorId)
            ->sum('item.quantity');

        return $balance - $reserved;
    }
}
