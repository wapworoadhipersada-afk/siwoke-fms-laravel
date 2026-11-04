<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Master\Application\Services\LocationHierarchy;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;

final class CreateLocation
{
    public function __construct(private readonly LocationHierarchy $hierarchy) {}

    /**
     * @param  array{type: LocationType, parent_id?: int|null, code: string, name: string, address?: string|null, latitude?: string|float|null, longitude?: string|float|null, is_active?: bool}  $data
     */
    public function handle(array $data): Location
    {
        $this->hierarchy->assertParent($data['type'], $data['parent_id'] ?? null);

        try {
            return DB::transaction(fn (): Location => Location::query()->create([
                ...$data,
                'parent_id' => $data['parent_id'] ?? null,
                'code' => Str::upper(trim($data['code'])),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => "Kode {$data['type']->label()} sudah dipakai."]);
        }
    }
}
