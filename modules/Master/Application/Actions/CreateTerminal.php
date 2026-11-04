<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Enums\TerminalStatus;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;

/**
 * Vendor and merchant are derived from the site, so a terminal can never be filed
 * under a merchant or bank it does not belong to.
 */
final class CreateTerminal
{
    /**
     * @param  array{merchant_site_id: int, tid: string, status?: TerminalStatus}  $data
     */
    public function handle(array $data): Terminal
    {
        $site = MerchantSite::query()->with('merchant')->find($data['merchant_site_id']);

        if (! $site instanceof MerchantSite) {
            throw ValidationException::withMessages(['merchant_site_id' => 'Site tidak ditemukan.']);
        }

        try {
            return Terminal::query()->create([
                'vendor_id' => $site->merchant->vendor_id,
                'merchant_id' => $site->merchant_id,
                'merchant_site_id' => $site->id,
                'tid' => Str::upper(trim($data['tid'])),
                'status' => $data['status'] ?? TerminalStatus::Active,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['tid' => 'TID ini sudah terdaftar untuk vendor tersebut.']);
        }
    }
}
