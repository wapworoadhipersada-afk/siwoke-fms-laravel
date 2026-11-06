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
 * Edits a location. The type is fixed; moving a node to another parent of the right type rewrites the path of
 * its whole subtree in the same transaction. Changing the vendor of a service point carries its areas along,
 * but is refused while merchants of another vendor are already in them.
 */
final class UpdateLocation
{
    public function __construct(private readonly LocationHierarchy $hierarchy) {}

    /**
     * @param  array{parent_id?: int|null, vendor_id?: int|null, code?: string, name?: string, address?: string|null, latitude?: string|float|null, longitude?: string|float|null, is_active?: bool}  $data
     */
    public function handle(Location $location, array $data): Location
    {
        $moving = array_key_exists('parent_id', $data) && $data['parent_id'] !== $location->parent_id;

        if ($moving) {
            $this->hierarchy->assertParent($location->type, $data['parent_id']);
        }

        $changingVendor = $location->type === LocationType::ServicePoint
            && array_key_exists('vendor_id', $data)
            && $data['vendor_id'] !== $location->vendor_id;

        if ($changingVendor) {
            $this->assertVendorChange($location, $data['vendor_id']);
        }

        // Only a service point chooses its vendor; a province has none and an area follows its service point.
        if ($location->type !== LocationType::ServicePoint) {
            unset($data['vendor_id']);
        }

        if (isset($data['code'])) {
            $data['code'] = Str::upper(trim($data['code']));
        }

        try {
            return DB::transaction(function () use ($location, $data, $moving, $changingVendor): Location {
                $oldPath = $location->path;
                $location->fill($data)->save();

                if ($moving) {
                    $location->refreshPath();

                    DB::update(
                        'UPDATE locations SET path = ? || substr(path, ?) WHERE path LIKE ? AND id <> ?',
                        [$location->path, strlen($oldPath) + 1, $oldPath.'%', $location->id],
                    );
                }

                if ($changingVendor) {
                    Location::query()->where('parent_id', $location->id)->update(['vendor_id' => $location->vendor_id]);
                }

                return $location->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => "Kode {$location->type->label()} sudah dipakai."]);
        }
    }

    private function assertVendorChange(Location $servicePoint, ?int $vendorId): void
    {
        if ($vendorId !== null && ! Vendor::query()->whereKey($vendorId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['vendor_id' => 'Vendor tidak ditemukan atau tidak aktif.']);
        }

        if ($vendorId === null) {
            return;
        }

        $others = DB::table('merchant_sites as site')
            ->join('merchants', 'merchants.id', '=', 'site.merchant_id')
            ->join('locations as area', 'area.id', '=', 'site.location_id')
            ->where('area.parent_id', $servicePoint->id)
            ->where('merchants.vendor_id', '<>', $vendorId)
            ->count();

        if ($others > 0) {
            throw ValidationException::withMessages(['vendor_id' => "Masih ada {$others} site milik vendor lain di service point ini; pindahkan atau hapus dulu."]);
        }
    }
}
