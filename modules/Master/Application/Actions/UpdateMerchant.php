<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\Merchant;

/**
 * The vendor of a merchant never changes: its terminals are bound to it.
 */
final class UpdateMerchant
{
    /**
     * @param  array{merchant_code?: string, name?: string, owner_name?: string|null, phone?: string|null, category?: string|null}  $data
     */
    public function handle(Merchant $merchant, array $data): Merchant
    {
        if (isset($data['merchant_code'])) {
            $data['merchant_code'] = Str::upper(trim($data['merchant_code']));
        }

        try {
            $merchant->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['merchant_code' => 'MID ini sudah terdaftar untuk vendor tersebut.']);
        }

        return $merchant;
    }
}
