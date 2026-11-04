<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\Vendor;

final class CreateVendor
{
    /**
     * @param  array{code: string, name: string, is_active?: bool, settings?: array<string, mixed>}  $data
     */
    public function handle(array $data): Vendor
    {
        try {
            return Vendor::query()->create([
                ...$data,
                'code' => Str::upper(trim($data['code'])),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode vendor sudah dipakai.']);
        }
    }
}
