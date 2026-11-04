<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Master\Domain\Models\Location;

final class CreateWarehouse
{
    /**
     * @param  array{location_id: int, code: string, name: string, pic_user_id?: int|null, is_active?: bool}  $data
     */
    public function handle(array $data): Warehouse
    {
        $this->assertReferences($data['location_id'], $data['pic_user_id'] ?? null);

        try {
            return Warehouse::query()->create([...$data, 'code' => Str::upper(trim($data['code']))]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode gudang sudah dipakai.']);
        }
    }

    public function assertReferences(int $locationId, ?int $picUserId): void
    {
        if (! Location::query()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Lokasi tidak ditemukan.']);
        }

        if ($picUserId !== null && ! User::query()->whereKey($picUserId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['pic_user_id' => 'PIC gudang tidak ditemukan atau tidak aktif.']);
        }
    }
}
