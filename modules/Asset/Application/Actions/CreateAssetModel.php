<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Domain\Models\AssetCategory;
use Modules\Asset\Domain\Models\AssetModel;

final class CreateAssetModel
{
    /**
     * @param  array{asset_category_id: int, brand: string, model_name: string, spec?: array<string, mixed>, is_active?: bool}  $data
     */
    public function handle(array $data): AssetModel
    {
        if (! AssetCategory::query()->whereKey($data['asset_category_id'])->exists()) {
            throw ValidationException::withMessages(['asset_category_id' => 'Kategori tidak ditemukan.']);
        }

        try {
            return AssetModel::query()->create([
                ...$data,
                'brand' => trim($data['brand']),
                'model_name' => trim($data['model_name']),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['model_name' => 'Jenis aset ini sudah terdaftar.']);
        }
    }
}
