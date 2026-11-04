<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\Merchant;

final class CreateMerchant
{
    /**
     * @param  array{vendor_id: int, merchant_code: string, name: string, owner_name?: string|null, phone?: string|null, category?: string|null}  $data
     */
    public function handle(array $data): Merchant
    {
        try {
            return Merchant::query()->create([
                ...$data,
                'merchant_code' => Str::upper(trim($data['merchant_code'])),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['merchant_code' => 'MID ini sudah terdaftar untuk vendor tersebut.']);
        }
    }
}
