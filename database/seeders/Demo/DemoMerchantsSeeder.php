<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Application\Actions\ImportMerchantDocument;
use Modules\Master\Application\Actions\SaveMerchantDocument;
use Modules\Master\Domain\Models\Vendor;

/**
 * Merchants and their TIDs, the way they really arrive: as documents from the principal. Each vendor gets
 * several imported documents (merchant + TID per line, some merchants with two TIDs) and one draft that still
 * has a problem line, so the Dokumen screen shows every state.
 */
final class DemoMerchantsSeeder extends Seeder
{
    private const DOCUMENTS_PER_VENDOR = 4;

    private const LINES_PER_DOCUMENT = 28;

    public function run(SaveMerchantDocument $save, ImportMerchantDocument $import): void
    {
        DemoSupport::seed();
        mt_srand(777);

        $actor = User::query()->role(RoleName::SuperAdmin->value)->orderBy('id')->firstOrFail();
        $tid = 10_000_000;
        $total = 0;

        foreach (Vendor::query()->whereIn('code', ['BRI', 'CIMB'])->get() as $vendor) {
            $areas = DB::table('locations as area')
                ->join('locations as sp', 'sp.id', '=', 'area.parent_id')
                ->join('locations as province', 'province.id', '=', 'sp.parent_id')
                ->where('area.type', 'area')->where('sp.vendor_id', $vendor->id)
                ->get(['area.name as area', 'sp.name as sp', 'province.name as province'])
                ->all();

            for ($number = 1; $number <= self::DOCUMENTS_PER_VENDOR; $number++) {
                $rows = [];
                $lastMid = null;

                for ($line = 0; $line < self::LINES_PER_DOCUMENT; $line++) {
                    $sameMerchant = $lastMid !== null && DemoSupport::chance(15);
                    $place = $sameMerchant ? $lastMid['place'] : DemoSupport::pick($areas);
                    $mid = $sameMerchant ? $lastMid['mid'] : '8'.DemoSupport::digits(14);
                    $name = $sameMerchant ? $lastMid['name'] : DemoSupport::merchantName(explode(' ', $place->area)[0]);
                    $lastMid = ['mid' => $mid, 'name' => $name, 'place' => $place];

                    $rows[] = [
                        'merchant_name' => $name,
                        'tid' => (string) (++$tid),
                        'mid' => $mid,
                        'address' => DemoSupport::address($place->area),
                        'province' => $place->province,
                        'service_point' => $place->sp,
                        'area' => $place->area,
                        'tid_note' => DemoSupport::chance(20) ? DemoSupport::pick(['EDC baru', 'Pindah lokasi', 'Tambah mesin', 'Merchant minta jadwal sore']) : '',
                    ];
                }

                $documentNumber = sprintf('B.%05d-MMS-%s/IJE/%02d/2026', mt_rand(10000, 99999), $vendor->code === 'BRI' ? 'JKE' : 'CMB', mt_rand(1, 10));
                $document = $save->handle($vendor, $documentNumber, now()->subDays(150 - $number * 30)->toDateString(), 'Dokumen demo '.$vendor->code, $rows, $actor, 'upload', "lampiran-{$vendor->code}-{$number}.xlsx");
                $result = $import->handle($document, $actor);
                $total += $result['imported'];
            }

            // A draft that has not been imported: two good lines and one whose area does not exist.
            $place = DemoSupport::pick($areas);
            $draft = [
                ['merchant_name' => DemoSupport::merchantName('Baru'), 'tid' => (string) (++$tid), 'mid' => '8'.DemoSupport::digits(14), 'address' => DemoSupport::address($place->area), 'province' => $place->province, 'service_point' => $place->sp, 'area' => $place->area, 'tid_note' => ''],
                ['merchant_name' => DemoSupport::merchantName('Baru'), 'tid' => (string) (++$tid), 'mid' => '', 'address' => DemoSupport::address($place->area), 'province' => $place->province, 'service_point' => $place->sp, 'area' => $place->area, 'tid_note' => 'Menunggu verifikasi'],
                ['merchant_name' => DemoSupport::merchantName('Luar Kota'), 'tid' => (string) (++$tid), 'mid' => '', 'address' => 'Jl. Tidak Dikenal 1', 'province' => '', 'service_point' => '', 'area' => 'Area Tidak Ada', 'tid_note' => ''],
            ];
            $save->handle($vendor, sprintf('B.%05d-MMS-DRAFT/%s/11/2026', mt_rand(10000, 99999), $vendor->code), now()->toDateString(), 'Draf: masih ada baris bermasalah', $draft, $actor, 'manual');
        }

        $this->command?->info("{$total} TID masuk lewat dokumen, ditambah 2 dokumen draf.");
    }
}
