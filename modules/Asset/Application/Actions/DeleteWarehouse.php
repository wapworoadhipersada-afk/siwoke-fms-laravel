<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Models\Warehouse;

final class DeleteWarehouse
{
    public function handle(Warehouse $warehouse): void
    {
        $holdsAssets = DB::table('assets')
            ->whereNull('deleted_at')
            ->where('holder_type', HolderType::Warehouse->value)
            ->where('holder_id', $warehouse->id)
            ->exists();

        $holdsStock = DB::table('asset_stocks')
            ->where('holder_type', HolderType::Warehouse->value)
            ->where('holder_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->exists();

        $hasPendingHandover = DB::table('asset_handovers')
            ->where('status', HandoverStatus::Pending->value)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('from_holder_type', HolderType::Warehouse->value)->where('from_holder_id', $warehouse->id))
                ->orWhere(fn ($query) => $query->where('to_holder_type', HolderType::Warehouse->value)->where('to_holder_id', $warehouse->id)))
            ->exists();

        if ($holdsAssets || $holdsStock || $hasPendingHandover) {
            throw ValidationException::withMessages(['warehouse' => 'Gudang masih menyimpan aset, stok, atau serah-terima yang belum selesai. Pindahkan terlebih dahulu.']);
        }

        $warehouse->delete();
    }
}
