<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\MerchantSite;

final class DeleteMerchantSite
{
    public function handle(MerchantSite $site): void
    {
        if ($site->terminals()->exists()) {
            throw ValidationException::withMessages(['site' => 'Site masih memiliki TID. Pindahkan atau hapus TID terlebih dahulu.']);
        }

        $site->delete();
    }
}
