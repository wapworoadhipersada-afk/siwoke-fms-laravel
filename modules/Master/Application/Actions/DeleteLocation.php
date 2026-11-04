<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\Location;

final class DeleteLocation
{
    public function handle(Location $location): void
    {
        if ($location->children()->exists()) {
            throw ValidationException::withMessages(['location' => 'Lokasi masih memiliki lokasi di bawahnya. Hapus atau pindahkan terlebih dahulu.']);
        }

        if ($location->sites()->exists()) {
            throw ValidationException::withMessages(['location' => 'Lokasi masih dipakai oleh site merchant.']);
        }

        $location->delete();
    }
}
