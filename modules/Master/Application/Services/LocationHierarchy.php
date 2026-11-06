<?php

declare(strict_types=1);

namespace Modules\Master\Application\Services;

use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;

/**
 * Enforces Province → Service Point → Area Kelolaan: every node sits directly under its own parent type.
 */
final class LocationHierarchy
{
    public function assertParent(LocationType $type, ?int $parentId): void
    {
        $expectedParentType = $type->parentType();

        if ($expectedParentType === null) {
            if ($parentId !== null) {
                throw ValidationException::withMessages(['parent_id' => "{$type->label()} tidak boleh memiliki induk."]);
            }

            return;
        }

        if ($parentId === null) {
            throw ValidationException::withMessages(['parent_id' => "{$type->label()} harus berada di bawah {$expectedParentType->label()}."]);
        }

        $parent = Location::query()->find($parentId);

        if (! $parent instanceof Location) {
            throw ValidationException::withMessages(['parent_id' => 'Lokasi induk tidak ditemukan.']);
        }

        if ($parent->type !== $expectedParentType) {
            throw ValidationException::withMessages(['parent_id' => "{$type->label()} harus berada di bawah {$expectedParentType->label()}, bukan {$parent->type->label()}."]);
        }
    }
}
