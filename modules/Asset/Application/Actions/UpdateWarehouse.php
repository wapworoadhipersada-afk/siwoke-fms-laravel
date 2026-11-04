<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Domain\Models\Warehouse;

final class UpdateWarehouse
{
    public function __construct(private readonly CreateWarehouse $create) {}

    /**
     * @param  array{location_id?: int, code?: string, name?: string, pic_user_id?: int|null, is_active?: bool}  $data
     */
    public function handle(Warehouse $warehouse, array $data): Warehouse
    {
        $this->create->assertReferences($data['location_id'] ?? $warehouse->location_id, $data['pic_user_id'] ?? null);

        if (isset($data['code'])) {
            $data['code'] = Str::upper(trim($data['code']));
        }

        try {
            $warehouse->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode gudang sudah dipakai.']);
        }

        return $warehouse;
    }
}
