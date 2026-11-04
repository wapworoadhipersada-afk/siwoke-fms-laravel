<?php

declare(strict_types=1);

namespace Modules\Ticket\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Models\ReasonCode;
use Modules\Ticket\Domain\Models\TicketType;

/**
 * The ticket types and the reasons a technician can give when a visit is pending or done negative.
 * Safe to run on every deploy; names and SLAs can be adjusted afterwards without being overwritten.
 */
class TicketCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['INSTALL', 'Pemasangan', 72],
            ['MAINTENANCE', 'Perawatan', 48],
            ['PULLOUT', 'Penarikan', 72],
            ['REPLACE', 'Pergantian mesin', 48],
        ];

        foreach ($types as [$code, $name, $sla]) {
            TicketType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'sla_hours' => $sla]);
        }

        $reasons = [
            [ReasonType::Pending, 'MERCHANT_NOT_READY', 'Merchant belum siap atau tidak ada di tempat'],
            [ReasonType::Pending, 'RESCHEDULE_REQUESTED', 'Merchant meminta jadwal ulang'],
            [ReasonType::Pending, 'PART_UNAVAILABLE', 'Mesin atau sparepart belum tersedia'],
            [ReasonType::Pending, 'NEED_VENDOR_CONFIRMATION', 'Menunggu konfirmasi vendor'],
            [ReasonType::Pending, 'NETWORK_ISSUE', 'Kendala jaringan di lokasi'],
            [ReasonType::Negative, 'MERCHANT_CLOSED_PERMANENTLY', 'Merchant tutup permanen'],
            [ReasonType::Negative, 'ADDRESS_NOT_FOUND', 'Alamat tidak ditemukan'],
            [ReasonType::Negative, 'MERCHANT_REFUSED', 'Merchant menolak'],
            [ReasonType::Negative, 'DUPLICATE_TICKET', 'Tiket ganda'],
            [ReasonType::Negative, 'NOT_APPLICABLE', 'Pekerjaan tidak relevan lagi'],
        ];

        foreach ($reasons as [$type, $code, $name]) {
            ReasonCode::query()->firstOrCreate(['type' => $type, 'code' => $code], ['name' => $name]);
        }
    }
}
