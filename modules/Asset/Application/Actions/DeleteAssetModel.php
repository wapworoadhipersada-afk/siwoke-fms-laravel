<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Domain\Models\AssetModel;

final class DeleteAssetModel
{
    public function handle(AssetModel $model): void
    {
        $inUse = $model->assets()->exists()
            || DB::table('asset_stocks')->where('asset_model_id', $model->id)->exists()
            || DB::table('asset_movement_items')->where('asset_model_id', $model->id)->exists();

        if ($inUse) {
            throw ValidationException::withMessages(['asset_model' => 'Jenis aset sudah memiliki unit atau riwayat. Nonaktifkan sebagai gantinya.']);
        }

        $model->delete();
    }
}
