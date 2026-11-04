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
 * A terminal may move to another site, even of another merchant, but never to another vendor's.
 */
final class UpdateTerminal
{
    /**
     * @param  array{merchant_site_id?: int, tid?: string, status?: TerminalStatus}  $data
     */
    public function handle(Terminal $terminal, array $data): Terminal
    {
        if (isset($data['merchant_site_id']) && $data['merchant_site_id'] !== $terminal->merchant_site_id) {
            $site = MerchantSite::query()->with('merchant')->find($data['merchant_site_id']);

            if (! $site instanceof MerchantSite) {
                throw ValidationException::withMessages(['merchant_site_id' => 'Site tidak ditemukan.']);
            }

            if ($site->merchant->vendor_id !== $terminal->vendor_id) {
                throw ValidationException::withMessages(['merchant_site_id' => 'TID tidak bisa dipindahkan ke site milik vendor lain.']);
            }

            $terminal->merchant_id = $site->merchant_id;
            $terminal->merchant_site_id = $site->id;
        }

        if (isset($data['tid'])) {
            $terminal->tid = Str::upper(trim($data['tid']));
        }

        if (isset($data['status'])) {
            $terminal->status = $data['status'];
        }

        try {
            $terminal->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['tid' => 'TID ini sudah terdaftar untuk vendor tersebut.']);
        }

        return $terminal;
    }
}
