<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\Vendor;

final class UpdateVendor
{
    /**
     * @param  array{code?: string, name?: string, is_active?: bool, settings?: array<string, mixed>}  $data
     */
    public function handle(Vendor $vendor, array $data): Vendor
    {
        if (isset($data['code'])) {
            $data['code'] = Str::upper(trim($data['code']));
        }

        try {
            $vendor->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode vendor sudah dipakai.']);
        }

        return $vendor;
    }
}
