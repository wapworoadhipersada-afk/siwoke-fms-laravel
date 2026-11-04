<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Master\Application\Services\LocationHierarchy;
use Modules\Master\Domain\Models\Location;

/**
 * Edits a location. The type is fixed; moving a node to another parent of the right type
 * rewrites the path of its whole subtree in the same transaction.
 */
final class UpdateLocation
{
    public function __construct(private readonly LocationHierarchy $hierarchy) {}

    /**
     * @param  array{parent_id?: int|null, code?: string, name?: string, address?: string|null, latitude?: string|float|null, longitude?: string|float|null, is_active?: bool}  $data
     */
    public function handle(Location $location, array $data): Location
    {
        $moving = array_key_exists('parent_id', $data) && $data['parent_id'] !== $location->parent_id;

        if ($moving) {
            $this->hierarchy->assertParent($location->type, $data['parent_id']);
        }

        if (isset($data['code'])) {
            $data['code'] = Str::upper(trim($data['code']));
        }

        try {
            return DB::transaction(function () use ($location, $data, $moving): Location {
                $oldPath = $location->path;

                $location->fill($data)->save();

                if ($moving) {
                    $location->refreshPath();

                    DB::update(
                        'UPDATE locations SET path = ? || substr(path, ?) WHERE path LIKE ? AND id <> ?',
                        [$location->path, strlen($oldPath) + 1, $oldPath.'%', $location->id],
                    );
                }

                return $location->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => "Kode {$location->type->label()} sudah dipakai."]);
        }
    }
}
