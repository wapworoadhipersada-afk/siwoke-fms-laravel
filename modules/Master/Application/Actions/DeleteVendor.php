<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\Vendor;

final class DeleteVendor
{
    public function handle(Vendor $vendor): void
    {
        if ($vendor->merchants()->exists() || $vendor->terminals()->exists()) {
            throw ValidationException::withMessages(['vendor' => 'Vendor masih memiliki merchant atau TID. Nonaktifkan vendor sebagai gantinya.']);
        }

        $vendor->delete();
    }
}
