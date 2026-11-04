<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\Merchant;

final class DeleteMerchant
{
    public function handle(Merchant $merchant): void
    {
        if ($merchant->terminals()->exists()) {
            throw ValidationException::withMessages(['merchant' => 'Merchant masih memiliki TID. Hapus TID terlebih dahulu.']);
        }

        DB::transaction(function () use ($merchant): void {
            // Sites exist only for their merchant, so they go with it (each deletion is audited).
            $merchant->sites->each->delete();
            $merchant->delete();
        });
    }
}
