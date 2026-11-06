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
use Modules\Master\Domain\Models\Vendor;

/**
 * Adds a province, a service point or a managed area. A service point names the vendor it works for (or none,
 * meaning every vendor); a managed area always takes the vendor of its service point; a province has none.
 */
final class CreateLocation
{
    public function __construct(private readonly LocationHierarchy $hierarchy) {}

    /**
     * @param  array{type: LocationType, parent_id?: int|null, vendor_id?: int|null, code: string, name: string, address?: string|null, latitude?: string|float|null, longitude?: string|float|null, is_active?: bool}  $data
     */
    public function handle(array $data): Location
    {
        $this->hierarchy->assertParent($data['type'], $data['parent_id'] ?? null);

        $vendorId = $this->vendorFor($data['type'], $data['parent_id'] ?? null, $data['vendor_id'] ?? null);

        try {
            return DB::transaction(fn (): Location => Location::query()->create([
                ...$data,
                'parent_id' => $data['parent_id'] ?? null,
                'vendor_id' => $vendorId,
                'code' => Str::upper(trim($data['code'])),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => "Kode {$data['type']->label()} sudah dipakai."]);
        }
    }

    private function vendorFor(LocationType $type, ?int $parentId, ?int $requested): ?int
    {
        if ($type === LocationType::Province) {
            return null;
        }

        if ($type === LocationType::Area) {
            return $parentId === null ? null : Location::query()->whereKey($parentId)->value('vendor_id');
        }

        if ($requested !== null && ! Vendor::query()->whereKey($requested)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['vendor_id' => 'Vendor tidak ditemukan atau tidak aktif.']);
        }

        return $requested;
    }
}
