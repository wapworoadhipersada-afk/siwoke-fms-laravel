<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Domain\Models\AssetModel;

/**
 * The category of a model never changes: it decides whether units or balances are tracked,
 * and existing history depends on that.
 */
final class UpdateAssetModel
{
    /**
     * @param  array{brand?: string, model_name?: string, spec?: array<string, mixed>, is_active?: bool}  $data
     */
    public function handle(AssetModel $model, array $data): AssetModel
    {
        try {
            $model->fill(Arr::only($data, ['brand', 'model_name', 'spec', 'is_active']))->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['model_name' => 'Jenis aset ini sudah terdaftar.']);
        }

        return $model;
    }
}
